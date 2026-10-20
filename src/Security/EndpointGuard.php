<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Security;

use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Contract\DnsResolverInterface;
use CloudCastle\Ssh\Exception\SecurityException;

/**
 * Валидация SSH-endpoint, DNS-resolve и защита от SSRF / DNS-rebinding через IP-pinning.
 *
 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
 * @SuppressWarnings("PHPMD.NPathComplexity")
 * @SuppressWarnings("PHPMD.ShortVariable")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
final class EndpointGuard
{
    /**
     * Создаёт guard с заданным DNS-резолвером.
     *
     * @param DnsResolverInterface $dns Резолвер имён (по умолчанию PhpDnsResolver)
     */
    public function __construct(
        /** DNS-резолвер имён. @var DnsResolverInterface */
        private readonly DnsResolverInterface $dns = new PhpDnsResolver(),
    ) {
    }

    /**
     * Проверяет форму endpoint и при включённом blockPrivateAddresses возвращает IP-pinned цель.
     *
     * @param string            $endpoint Сырой endpoint (host, host:port, [ipv6]:port или URL)
     * @param ConnectionOptions $options  Опции; важен флаг blockPrivateAddresses()
     *
     * @return PinnedEndpoint Готовый к connect endpoint, host, IP-список и порт
     *
     * @throws SecurityException При пустом/битом endpoint или запрещённых/нерезолвимых адресах
     */
    public function prepare(string $endpoint, ConnectionOptions $options): PinnedEndpoint
    {
        $endpoint = trim($endpoint);
        $this->assertEndpointShape($endpoint);
        $host = $this->extractHost($endpoint) ?? '';
        $port = $this->extractPort($endpoint);

        if (!$options->blockPrivateAddresses()) {
            return new PinnedEndpoint($endpoint, $host !== '' ? $host : null, [], $port);
        }

        if ($host === '') {
            return new PinnedEndpoint($endpoint, null, [], $port);
        }

        $ips = $this->resolvePublicIps($host);
        $pinnedHost = $ips[0];
        $connectEndpoint = $this->replaceHost($endpoint, $pinnedHost);

        return new PinnedEndpoint($connectEndpoint, $host, $ips, $port);
    }

    /**
     * Извлекает hostname/IP из endpoint без порта и схемы.
     *
     * @param string $endpoint Строка цели подключения
     *
     * @return string|null Host в lowercase; null если URL без host или битые скобки IPv6
     */
    public function extractHost(string $endpoint): ?string
    {
        if (str_contains($endpoint, '://')) {
            return $this->hostFromUrl($endpoint);
        }

        if (str_starts_with($endpoint, '[')) {
            return $this->hostFromBracket($endpoint);
        }

        return $this->hostFromHostPort($endpoint);
    }

    /**
     * Извлекает TCP-порт из endpoint; при отсутствии — 22.
     *
     * @param string $endpoint Строка цели подключения
     *
     * @return int Порт 1…65535 из строки или 22 по умолчанию
     */
    public function extractPort(string $endpoint): int
    {
        if (str_contains($endpoint, '://')) {
            return $this->portFromUrl($endpoint);
        }

        if (str_starts_with($endpoint, '[')) {
            return $this->portFromBracket($endpoint);
        }

        return $this->portFromHostPort($endpoint);
    }

    /**
     * Порт из URL со схемой (ssh://host:port).
     *
     * @param string $endpoint Endpoint с ://
     *
     * @return int Порт из URL или 22
     */
    private function portFromUrl(string $endpoint): int
    {
        $parts = parse_url($endpoint);

        if (is_array($parts) && isset($parts['port'])) {
            return $parts['port'];
        }

        return 22;
    }

    /**
     * Порт из IPv6-формы [addr]:port.
     *
     * @param string $endpoint Endpoint, начинающийся с [
     *
     * @return int Порт после ] или 22
     */
    private function portFromBracket(string $endpoint): int
    {
        $end = strpos($endpoint, ']');

        if ($end === false) {
            return 22;
        }

        if (!isset($endpoint[$end + 1]) || $endpoint[$end + 1] !== ':') {
            return 22;
        }

        $port = substr($endpoint, $end + 2);

        return ctype_digit($port) ? (int) $port : 22;
    }

    /**
     * Порт из host:port (последнее двоеточие).
     *
     * @param string $endpoint Host или host:port
     *
     * @return int Порт или 22
     */
    private function portFromHostPort(string $endpoint): int
    {
        $colon = strrpos($endpoint, ':');

        if (!is_int($colon) || !ctype_digit(substr($endpoint, $colon + 1))) {
            return 22;
        }

        return (int) substr($endpoint, $colon + 1);
    }

    /**
     * Резолвит hostname в список публичных IP; литерал IP проверяет на блокировку.
     *
     * @param string $host Имя хоста или IP-литерал
     *
     * @return list<string> Непустой список публичных адресов
     *
     * @throws SecurityException При localhost, private/reserved IP, пустом DNS или blocked A/AAAA
     */
    public function resolvePublicIps(string $host): array
    {
        if ($this->isLocalHostname($host)) {
            throw new SecurityException(sprintf('Запрещённый адрес (SSRF-guard): %s', $host));
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if ($this->isBlockedIp($host)) {
                throw new SecurityException(sprintf('Запрещённый адрес (SSRF-guard): %s', $host));
            }

            return [$host];
        }

        $ips = $this->dns->resolve($host);

        if ($ips === []) {
            throw new SecurityException(sprintf('Не удалось резолвить host (SSRF-guard): %s', $host));
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new SecurityException(sprintf(
                    'DNS вернул запрещённый адрес (SSRF-guard): %s → %s',
                    $host,
                    $ip,
                ));
            }
        }

        return $ips;
    }

    /**
     * Проверяет, что endpoint не пуст и не содержит управляющих символов.
     *
     * @param string $endpoint Уже trim-нутая строка
     *
     * @throws SecurityException При пустой строке, NUL или C0/DEL
     */
    private function assertEndpointShape(string $endpoint): void
    {
        if ($endpoint === '') {
            throw new SecurityException('Пустой endpoint запрещён');
        }

        if (str_contains($endpoint, "\0") || preg_match('/[\x01-\x1F\x7F]/', $endpoint) === 1) {
            throw new SecurityException('Endpoint содержит управляющие символы');
        }
    }

    /**
     * Определяет локальные/loopback имена, запрещённые при SSRF-guard.
     *
     * @param string $host Имя или адрес
     *
     * @return bool true для localhost, *.localhost и 0.0.0.0
     */
    private function isLocalHostname(string $host): bool
    {
        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || $host === '0.0.0.0';
    }

    /**
     * Извлекает host из URL со схемой через parse_url.
     *
     * @param string $endpoint Строка с `://`
     *
     * @return string|null Host в lowercase без скобок IPv6; null если host отсутствует
     */
    private function hostFromUrl(string $endpoint): ?string
    {
        $parts = parse_url($endpoint);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if (!is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    /**
     * Извлекает IPv6 из формы `[addr]:port` или `[addr]`.
     *
     * @param string $endpoint Строка, начинающаяся с `[`
     *
     * @return string|null Адрес без скобок в lowercase; null если нет закрывающей `]`
     */
    private function hostFromBracket(string $endpoint): ?string
    {
        $end = strpos($endpoint, ']');

        if ($end === false) {
            return null;
        }

        return strtolower(substr($endpoint, 1, $end - 1));
    }

    /**
     * Извлекает host из формы `host` или `host:port` (порт — только цифры).
     *
     * @param string $endpoint Строка без схемы и без ведущих `[`
     *
     * @return string Host в lowercase (может быть пустой строкой)
     */
    private function hostFromHostPort(string $endpoint): string
    {
        $candidate = $endpoint;
        $colon = strrpos($candidate, ':');

        if (is_int($colon) && ctype_digit(substr($candidate, $colon + 1))) {
            $candidate = substr($candidate, 0, $colon);
        }

        return strtolower($candidate);
    }

    /**
     * Подменяет host в endpoint на pinned IP, сохраняя схему, порт и path.
     *
     * @param string $endpoint Исходный endpoint
     * @param string $ip       Публичный IP для connect (IPv6 оформляется в скобки)
     *
     * @return string Endpoint с подставленным IP
     */
    private function replaceHost(string $endpoint, string $ip): string
    {
        $formattedIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;

        if (str_contains($endpoint, '://')) {
            return $this->replaceHostWithScheme($endpoint, $formattedIp);
        }

        if (str_starts_with($endpoint, '[')) {
            return $this->replaceBracketHost($endpoint, $formattedIp);
        }

        return $this->replacePlainHost($endpoint, $formattedIp);
    }

    /**
     * Подмена host в host:port без схемы и без IPv6-скобок.
     *
     * @param string $endpoint    Исходный endpoint
     * @param string $formattedIp IP уже в форме для URI
     *
     * @return string Endpoint с подставленным IP
     */
    private function replacePlainHost(string $endpoint, string $formattedIp): string
    {
        $colon = strrpos($endpoint, ':');

        if (is_int($colon) && ctype_digit(substr($endpoint, $colon + 1))) {
            return $formattedIp . substr($endpoint, $colon);
        }

        return $formattedIp;
    }

    /**
     * Подмена host в URL со схемой (tcp://host:port/path).
     *
     * @param string $endpoint    Исходный endpoint со схемой
     * @param string $formattedIp IP уже в форме для URI (IPv6 в скобках)
     *
     * @return string Endpoint с подставленным IP
     */
    private function replaceHostWithScheme(string $endpoint, string $formattedIp): string
    {
        $parts = parse_url($endpoint);

        if (!is_array($parts)) { // @codeCoverageIgnore
            return $endpoint; // @codeCoverageIgnore
        }

        $scheme = $parts['scheme'] ?? 'tcp';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';

        return $scheme . '://' . $formattedIp . $port . $path;
    }

    /**
     * Подмена host в литерале [ipv6]:port.
     *
     * @param string $endpoint    Исходный endpoint вида [addr]...
     * @param string $formattedIp IP уже в форме для URI
     *
     * @return string Endpoint с подставленным IP
     */
    private function replaceBracketHost(string $endpoint, string $formattedIp): string
    {
        $end = strpos($endpoint, ']');

        if ($end === false) {
            return $formattedIp;
        }

        return $formattedIp . substr($endpoint, $end + 1);
    }

    /**
     * Проверяет, что IP входит в private/reserved диапазон (блокируется SSRF-guard).
     *
     * @param string $ip IPv4 или IPv6 литерал
     *
     * @return bool true если адрес не проходит FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE
     */
    private function isBlockedIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
