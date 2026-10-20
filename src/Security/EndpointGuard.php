<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Security;

use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Contract\DnsResolverInterface;
use CloudCastle\Ssh\Exception\SecurityException;

/**
 * Валидация endpoint, DNS-resolve и защита от SSRF / DNS-rebinding.
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
     * @param DnsResolverInterface $dns Резолвер DNS
     */
    public function __construct(
        private readonly DnsResolverInterface $dns = new PhpDnsResolver(),
    ) {
    }

    /**
     * Проверяет endpoint и возвращает IP-pinned вариант.
     *
     * @throws SecurityException
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
     * Извлекает host из endpoint.
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
     * Извлекает TCP-порт (по умолчанию 22).
     */
    public function extractPort(string $endpoint): int
    {
        if (str_contains($endpoint, '://')) {
            $parts = parse_url($endpoint);

            if (is_array($parts) && isset($parts['port'])) {
                return $parts['port'];
            }

            return 22;
        }

        if (str_starts_with($endpoint, '[')) {
            $end = strpos($endpoint, ']');

            if ($end !== false && isset($endpoint[$end + 1]) && $endpoint[$end + 1] === ':') {
                $port = substr($endpoint, $end + 2);

                if (ctype_digit($port)) {
                    return (int) $port;
                }
            }

            return 22;
        }

        $colon = strrpos($endpoint, ':');

        if (is_int($colon) && ctype_digit(substr($endpoint, $colon + 1))) {
            return (int) substr($endpoint, $colon + 1);
        }

        return 22;
    }

    /**
     * Резолвит hostname в публичные IP.
     *
     * @return list<string>
     *
     * @throws SecurityException
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
     * @throws SecurityException
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

    private function isLocalHostname(string $host): bool
    {
        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || $host === '0.0.0.0';
    }

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

    private function hostFromBracket(string $endpoint): ?string
    {
        $end = strpos($endpoint, ']');

        if ($end === false) {
            return null;
        }

        return strtolower(substr($endpoint, 1, $end - 1));
    }

    private function hostFromHostPort(string $endpoint): string
    {
        $candidate = $endpoint;
        $colon = strrpos($candidate, ':');

        if (is_int($colon) && ctype_digit(substr($candidate, $colon + 1))) {
            $candidate = substr($candidate, 0, $colon);
        }

        return strtolower($candidate);
    }

    private function replaceHost(string $endpoint, string $ip): string
    {
        $formattedIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;

        if (str_contains($endpoint, '://')) {
            $parts = parse_url($endpoint);

            if (!is_array($parts)) { // @codeCoverageIgnore
                return $endpoint; // @codeCoverageIgnore
            }

            $scheme = $parts['scheme'] ?? 'tcp';
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';
            $path = $parts['path'] ?? '';

            return $scheme . '://' . $formattedIp . $port . $path;
        }

        if (str_starts_with($endpoint, '[')) {
            $end = strpos($endpoint, ']');

            if ($end === false) {
                return $formattedIp;
            }

            return $formattedIp . substr($endpoint, $end + 1);
        }

        $colon = strrpos($endpoint, ':');

        if (is_int($colon) && ctype_digit(substr($endpoint, $colon + 1))) {
            return $formattedIp . substr($endpoint, $colon);
        }

        return $formattedIp;
    }

    private function isBlockedIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
