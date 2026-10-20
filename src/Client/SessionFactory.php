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
        $timeoutSeconds = max(1, (int) $options->connectTimeout());
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client($target, $errno, $errstr, (float) $timeoutSeconds);

        if ($stream === false) {
            throw new ConnectionException(sprintf(
                'TCP к %s:%d не удался: [%d] %s',
                $host,
                $port,
                $errno,
                $errstr,
            ));
        }

        stream_set_timeout($stream, max(1, (int) $options->ioTimeout()));
        $session = new NativeSession($stream);
        $this->hostKeys->verify($session->hostKeyFingerprint(), $options);
        $session->login($auth);
        $session->bindEndpoint($endpoint);

        return $session;
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

        $host = $parts['host'];

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        $port = $parts['port'] ?? $fallbackPort;

        return [$host, $port, $this->tcpTarget($host, $port)];
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
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        $isIpv6 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            || str_contains($host, ':');

        return 'tcp://' . ($isIpv6 ? '[' . $host . ']' : $host) . ':' . $port;
    }
}
