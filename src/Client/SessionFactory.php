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
use CloudCastle\Ssh\Session\NativeSession;

/**
 * Фабрика нативных SSH-сессий с SSRF-guard и host-key verify.
 *
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
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
        bool $sftp = false,
    ): SessionInterface {
        $options ??= ConnectionOptions::defaults();
        $pinned = $this->guard->prepare($endpoint, $options);
        $host = $pinned->host();
        $port = $pinned->port() > 0 ? $pinned->port() : 22;

        if ($host === '') {
            $host = $endpoint;
        }

        $timeout = (int) max(1, (int) $options->connectTimeout());
        $errno = 0;
        $errstr = '';
        $target = 'tcp://' . (str_contains($host, ':') && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $host . ']' : $host) . ':' . $port;

        if (str_contains($pinned->connectEndpoint(), '://')) {
            $parts = parse_url($pinned->connectEndpoint());

            if (is_array($parts) && isset($parts['host']) && is_string($parts['host'])) {
                $h = $parts['host'];
                $p = isset($parts['port']) && is_int($parts['port']) ? $parts['port'] : $port;
                $target = 'tcp://' . (str_contains($h, ':') ? '[' . $h . ']' : $h) . ':' . $p;
                $host = $h;
                $port = $p;
            }
        } elseif ($pinned->resolvedIps() !== []) {
            $ip = $pinned->resolvedIps()[0];
            $target = 'tcp://' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip) . ':' . $port;
        }

        $stream = @stream_socket_client($target, $errno, $errstr, (float) $timeout);

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
        $session = new NativeSession($stream, $auth, $sftp);
        $this->hostKeys->verify($session->hostKeyFingerprint(), $options);
        $session->bindEndpoint($endpoint);

        return $session;
    }
}
