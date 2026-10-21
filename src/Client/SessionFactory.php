<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Client;

use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Contract\SessionFactoryInterface;
use CloudCastle\Ssh\Contract\SessionInterface;
use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Exception\HostKeyException;
use CloudCastle\Ssh\Exception\SecurityException;
use CloudCastle\Ssh\Security\EndpointGuard;
use CloudCastle\Ssh\Security\HostKeyVerifier;
use CloudCastle\Ssh\Security\PinnedEndpoint;
use CloudCastle\Ssh\Session\NativeSession;

/**
 * Фабрика нативных SSH-сессий: SSRF-guard, TCP connect, handshake, host-key verify, затем auth.
 *
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.ShortVariable")
 */
final class SessionFactory implements SessionFactoryInterface
{
    /**
     * Создаёт фабрику с заданными guard и verifier (удобно для тестов/DI).
     *
     * @param EndpointGuard   $guard    Проверка endpoint и DNS-pinning
     * @param HostKeyVerifier $hostKeys Проверка fingerprint host key
     */
    public function __construct(
        /** SSRF-guard и DNS-pinning. @var EndpointGuard */
        private readonly EndpointGuard $guard = new EndpointGuard(),
        /** Проверка fingerprint host key. @var HostKeyVerifier */
        private readonly HostKeyVerifier $hostKeys = new HostKeyVerifier(),
    ) {
    }

    /**
     * Устанавливает TCP, поднимает NativeSession, проверяет host key, затем login.
     *
     * @param string                 $endpoint Цель connect
     * @param AuthCredentials        $auth     Password или publickey
     * @param ConnectionOptions|null $options  Опции; null — ConnectionOptions::defaults()
     *
     * @return SessionInterface Готовая сессия с привязанным endpoint
     *
     * @throws ConnectionException При ошибке TCP или протокола
     * @throws SecurityException   При срабатывании SSRF-guard
     * @throws HostKeyException    При отклонении host key
     */
    #[\Override]
    public function connect(
        string $endpoint,
        AuthCredentials $auth,
        ?ConnectionOptions $options = null,
    ): SessionInterface {
        $options ??= ConnectionOptions::defaults();
        $pinned = $this->guard->prepare($endpoint, $options);
        [$host, $port, $target] = $this->buildTarget($pinned, $endpoint);
        $stream = $this->openTcpStream(
            $target,
            $this->positiveTimeoutSeconds($options->connectTimeout()),
            $this->positiveTimeoutSeconds($options->ioTimeout()),
            $host,
            $port,
        );
        $session = new NativeSession($stream);
        $this->hostKeys->verify($session->hostKeyFingerprint(), $options);
        $session->login($auth);
        $session->bindEndpoint($endpoint);

        return $session;
    }

    /**
     * Целые секунды таймаута с нижней границей 1.
     *
     * @param float $timeout Исходный таймаут из ConnectionOptions
     *
     * @return int Секунды ≥ 1
     */
    private function positiveTimeoutSeconds(float $timeout): int
    {
        return max(1, (int) $timeout);
    }

    /**
     * Начальное значение errno до вызова stream_socket_client.
     */
    private function initialSocketErrno(): int
    {
        return 0;
    }

    /**
     * Открывает TCP stream, выставляет I/O timeout или бросает ConnectionException.
     *
     * @param string $target         URI tcp://…
     * @param int    $connectSeconds Таймаут connect ≥ 1
     * @param int    $ioSeconds      Таймаут I/O ≥ 1
     * @param string $host           Host для текста ошибки
     * @param int    $port           Port для текста ошибки
     *
     * @return resource Открытый stream
     *
     * @throws ConnectionException При неудачном connect или set_timeout
     */
    private function openTcpStream(
        string $target,
        int $connectSeconds,
        int $ioSeconds,
        string $host,
        int $port,
    ): mixed {
        $errno = $this->initialSocketErrno();
        $errstr = '';
        $stream = @stream_socket_client($target, $errno, $errstr, $connectSeconds);

        if ($stream === false) {
            throw new ConnectionException(sprintf(
                'TCP к %s:%d не удался: [%d] %s',
                $host,
                $port,
                $errno,
                $errstr,
            ));
        }

        return $this->applyIoTimeout($stream, $ioSeconds);
    }

    /**
     * Выставляет stream_set_timeout и возвращает тот же stream; при неуспехе — ConnectionException.
     *
     * @param mixed $stream         TCP stream или не-resource (тогда исключение)
     * @param int   $timeoutSeconds I/O timeout ≥ 1
     *
     * @return resource Тот же $stream после set_timeout
     *
     * @throws ConnectionException Если stream_set_timeout вернул не true
     */
    private function applyIoTimeout(mixed $stream, int $timeoutSeconds): mixed
    {
        if (!is_resource($stream)) {
            throw new ConnectionException('Не удалось выставить I/O timeout SSH stream');
        }

        $applied = stream_set_timeout($stream, $timeoutSeconds);

        if (!$applied) {
            throw new ConnectionException('Не удалось выставить I/O timeout SSH stream');
        }

        return $stream;
    }

    /**
     * Собирает host, port и tcp:// target из PinnedEndpoint.
     *
     * @param PinnedEndpoint $pinned   Результат EndpointGuard::prepare
     * @param string         $endpoint Исходный endpoint (fallback для пустого host)
     *
     * @return array{0: string, 1: int, 2: string} [host, port, tcpTarget]
     */
    private function buildTarget(PinnedEndpoint $pinned, string $endpoint): array
    {
        $host = $pinned->host();
        $port = $pinned->port() > 0 ? $pinned->port() : 22;

        if ($host === '') {
            $host = $endpoint;
        }

        $connectEndpoint = $pinned->connectEndpoint();

        if (str_contains($connectEndpoint, '://')) {
            return $this->targetFromUrl($connectEndpoint, $host, $port);
        }

        if ($pinned->resolvedIps() !== []) {
            $address = $pinned->resolvedIps()[0];

            return [$host, $port, $this->tcpTarget($address, $port)];
        }

        return [$host, $port, $this->tcpTarget($host, $port)];
    }

    /**
     * Строит target из URL-подобного connectEndpoint (parse_url).
     *
     * @param string $connectEndpoint Строка со схемой `://`
     * @param string $fallbackHost    Host при неудачном parse
     * @param int    $fallbackPort    Port при отсутствии в URL
     *
     * @return array{0: string, 1: int, 2: string} [host, port, tcpTarget]
     */
    private function targetFromUrl(string $connectEndpoint, string $fallbackHost, int $fallbackPort): array
    {
        $parts = parse_url($connectEndpoint);

        if (!is_array($parts) || !isset($parts['host'])) {
            return [$fallbackHost, $fallbackPort, $this->tcpTarget($fallbackHost, $fallbackPort)];
        }

        $host = $this->unwrapBracketHost($parts['host']);
        $port = $parts['port'] ?? $fallbackPort;

        return [$host, $port, $this->tcpTarget($host, $port)];
    }

    /**
     * Снимает квадратные скобки IPv6-литерала `[addr]`.
     *
     * @param string $host Host из parse_url или PinnedEndpoint
     *
     * @return string Host без обрамляющих []
     */
    private function unwrapBracketHost(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    /**
     * Формирует URI `tcp://host:port` с квадратными скобками для IPv6.
     *
     * @param string $host Имя или IP (скобки снимаются, если уже были)
     * @param int    $port TCP-порт
     *
     * @return string Адрес для stream_socket_client
     */
    private function tcpTarget(string $host, int $port): string
    {
        $host = $this->unwrapBracketHost($host);
        $isIpv6 = $this->needsIpv6Brackets($host);

        return 'tcp://' . ($isIpv6 ? '[' . $host . ']' : $host) . ':' . $port;
    }

    /**
     * Нужны ли скобки вокруг host в tcp:// URI.
     *
     * @param string $host Host без скобок
     *
     * @return bool true для IPv6 или host с ':'
     */
    private function needsIpv6Brackets(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return true;
        }

        return str_contains($host, ':');
    }
}
