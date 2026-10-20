<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Forward;

use CloudCastle\Ssh\Contract\SessionInterface;
use CloudCastle\Ssh\Exception\ConnectionException;

/**
 * Локальный port-forward через SSH channel direct-tcpip (упрощённый API).
 *
 * Реализация использует remote `nc`/`bash /dev/tcp` как совместимый транспорт,
 * когда native channel-forward ещё не поднят на конкретной сессии.
 */
final class LocalPortForward
{
    /**
     * @param SessionInterface $session Активная сессия
     */
    public function __construct(private readonly SessionInterface $session)
    {
    }

    /**
     * Проксирует TCP-запрос к host:port со стороны SSH-сервера и возвращает ответ.
     *
     * @throws ConnectionException
     */
    public function request(string $remoteHost, int $remotePort, string $payload, float $timeout = 5.0): string
    {
        if ($remotePort < 1 || $remotePort > 65535) {
            throw new ConnectionException('Некорректный remote port для forward');
        }

        $host = escapeshellarg($remoteHost);
        $port = (string) $remotePort;
        $b64 = base64_encode($payload);
        $timeoutSeconds = max(1, (int) $timeout);
        $cmd = sprintf(
            'timeout %s bash -c %s',
            escapeshellarg((string) $timeoutSeconds),
            escapeshellarg(
                'echo ' . escapeshellarg($b64) . ' | base64 -d | nc -w ' . $timeoutSeconds
                . ' ' . trim($host, "'") . ' ' . $port . ' | base64 -w0',
            ),
        );
        $encoded = trim($this->session->exec($cmd));
        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            return $encoded;
        }

        return $decoded;
    }
}
