<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Scp;

use CloudCastle\Ssh\Contract\SessionInterface;
use CloudCastle\Ssh\Exception\ConnectionException;

/**
 * Remote-command fallback для копирования файлов через SSH exec.
 *
 * Это НЕ полноценный SCP-протокол (нет framing/ACK): get/put выполняют
 * удалённые shell-команды (`scp -f` / printf|base64). Для protocol-compatible
 * SCP/SFTP используйте отдельный пакет.
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
     * Скачивает удалённый файл remote-командой `scp -f` (fallback, не SCP framing).
     *
     * @param string $remotePath Абсолютный или относительный путь на сервере
     *
     * @return string Непустой вывод команды scp -f
     *
     * @throws ConnectionException Если ответ пустой
     */
    public function get(string $remotePath): string
    {
        return $this->getViaRemoteCommand($remotePath);
    }

    /**
     * Загружает содержимое remote-командой printf|base64 (fallback, не SCP framing).
     *
     * @param string $remotePath Путь назначения на сервере
     * @param string $contents   Байты файла (бинарные допустимы)
     *
     * @throws ConnectionException При ошибке exec на сессии
     */
    public function put(string $remotePath, string $contents): void
    {
        $this->putViaRemoteCommand($remotePath, $contents);
    }

    /**
     * Remote-command fallback: `scp -f` без protocol ACK.
     *
     * @param string $remotePath Путь на сервере
     *
     * @return string Непустой stdout
     */
    public function getViaRemoteCommand(string $remotePath): string
    {
        $escaped = $this->escape($remotePath);
        $output = $this->session->exec('scp -f ' . $escaped);

        if ($output === '') {
            throw new ConnectionException('SCP get: пустой ответ');
        }

        return $output;
    }

    /**
     * Remote-command fallback: запись через printf|base64.
     *
     * @param string $remotePath Путь назначения
     * @param string $contents   Байты файла
     */
    public function putViaRemoteCommand(string $remotePath, string $contents): void
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
