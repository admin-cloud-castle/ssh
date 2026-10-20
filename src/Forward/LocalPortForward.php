<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Forward;

use CloudCastle\Ssh\Contract\SessionInterface;
use CloudCastle\Ssh\Exception\ConnectionException;

/**
 * Упрощённый local port-forward: TCP-запрос с стороны SSH-сервера через remote nc/bash.
 *
 * Используется, когда native channel direct-tcpip ещё не реализован на сессии.
 */
final class LocalPortForward
{
    /**
     * Создаёт helper forward над активной сессией.
     *
     * @param SessionInterface $session Подключённая SSH-сессия
     */
    public function __construct(
        /** Активная SSH-сессия. @var SessionInterface */
        private readonly SessionInterface $session,
    ) {
    }

    /**
     * Отправляет $payload на $remoteHost:$remotePort со стороны сервера и возвращает ответ.
     *
     * Ответ ожидается в base64; если декодирование не удалось — возвращается сырой stdout.
     *
     * @param string $remoteHost Хост, видимый с SSH-сервера (не обязательно публичный)
     * @param int    $remotePort TCP-порт 1…65535
     * @param string $payload    Байты запроса к удалённому сервису
     * @param float  $timeout    Таймаут remote-команды в секундах (минимум 1 после округления)
     *
     * @return string Декодированный ответ или сырой stdout exec
     *
     * @throws ConnectionException При некорректном порте или ошибке exec
     */
    public function request(string $remoteHost, int $remotePort, string $payload, float $timeout = 5.0): string
    {
        if ($remotePort < 1 || $remotePort > 65535) {
            throw new ConnectionException('Некорректный remote port для forward');
        }

        $host = escapeshellarg($remoteHost);
        $port = sprintf('%d', $remotePort);
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
