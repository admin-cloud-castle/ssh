<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Scp;

use CloudCastle\Ssh\Contract\SessionInterface;
use CloudCastle\Ssh\Exception\ConnectionException;

/**
 * Клиент SCP поверх SSH exec (без отдельного SCP/SFTP subsystem).
 */
final class ScpClient
{
    /**
     * Создаёт SCP-клиент над активной SSH-сессией.
     *
     * @param SessionInterface $session Подключённая сессия для exec
     */
    public function __construct(
        /** Активная SSH-сессия. @var SessionInterface */
        private readonly SessionInterface $session,
    ) {
    }

    /**
     * Скачивает удалённый файл через `scp -f` и возвращает тело ответа.
     *
     * @param string $remotePath Абсолютный или относительный путь на сервере
     *
     * @return string Непустой вывод команды scp -f
     *
     * @throws ConnectionException Если ответ пустой
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
     * Загружает содержимое в remote-файл через base64 + shell redirect.
     *
     * @param string $remotePath Путь назначения на сервере
     * @param string $contents   Байты файла (бинарные допустимы)
     *
     * @throws ConnectionException При ошибке exec на сессии
     */
    public function put(string $remotePath, string $contents): void
    {
        $escaped = $this->escape($remotePath);
        $b64 = base64_encode($contents);
        $this->session->exec('printf %s ' . escapeshellarg($b64) . ' | base64 -d > ' . $escaped);
    }

    /**
     * Экранирует путь для безопасной подстановки в remote shell.
     *
     * @param string $path Сырой путь
     *
     * @return string Результат escapeshellarg
     */
    private function escape(string $path): string
    {
        return escapeshellarg($path);
    }
}
