<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Client;

use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Contract\SessionFactoryInterface;
use CloudCastle\Ssh\Contract\SessionInterface;
use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Security\EndpointGuard;
use CloudCastle\Ssh\Security\HostKeyVerifier;
use CloudCastle\Ssh\Security\PinnedEndpoint;
use CloudCastle\Ssh\Session\NativeSession;

/**
 * Фабрика нативных SSH-сессий с SSRF-guard и host-key verify.
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
     * @param EndpointGuard   $guard    Проверка endpoint / DNS-pinning
     * @param HostKeyVerifier $hostKeys Проверка host key
     */
    public function __construct(
        private readonly EndpointGuard $guard = new EndpointGuard(),
        private readonly HostKeyVerifier $hostKeys = new HostKeyVerifier(),
    ) {
    }

    /**
     * {@inheritdoc}
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
        $session = new NativeSession($stream, $auth);
        $this->hostKeys->verify($session->hostKeyFingerprint(), $options);
        $session->bindEndpoint($endpoint);

        return $session;
    }

    /**
     * Собирает host/port/target для stream_socket_client.
     *
     * @return array{0: string, 1: int, 2: string}
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
     * Target из URL-подобного pinned endpoint.
     *
     * @return array{0: string, 1: int, 2: string}
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
     * Формирует tcp:// URI с учётом IPv6.
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
