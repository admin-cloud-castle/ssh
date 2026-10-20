<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Scp;

use CloudCastle\Ssh\Contract\SessionInterface;
use CloudCastle\Ssh\Exception\ConnectionException;

/**
 * SCP поверх SSH exec (без отдельного subsystem).
 */
final class ScpClient
{
    /**
     * @param SessionInterface $session Активная сессия
     */
    public function __construct(private readonly SessionInterface $session)
    {
    }

    /**
     * Скачивает remote-файл через `scp -f`.
     *
     * @throws ConnectionException
     */
    public function get(string $remotePath): string
    {
        $escaped = $this->escape($remotePath);
        $output = $this->session->exec('scp -f ' . $escaped);

        if ($output === '') {
            throw new ConnectionException('SCP get: пустой ответ');
        }

        return $output;
    }

    /**
     * Загружает содержимое через временный printf/cat (совместимый fallback).
     *
     * @throws ConnectionException
     */
    public function put(string $remotePath, string $contents): void
    {
        $escaped = $this->escape($remotePath);
        $b64 = base64_encode($contents);
        $this->session->exec('printf %s ' . escapeshellarg($b64) . ' | base64 -d > ' . $escaped);
    }

    private function escape(string $path): string
    {
        return escapeshellarg($path);
    }
}
