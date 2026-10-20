<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Sftp;

use CloudCastle\Ssh\Exception\ConnectionException;

/**
 * Низкоуровневый канал SFTP поверх SSH session-канала.
 */
interface SftpChannelInterface
{
    /**
     * Отправляет SFTP payload (без внешнего uint32 length).
     *
     * @throws ConnectionException
     */
    public function sendSftp(string $sftpPayload): void;

    /**
     * Принимает один SFTP payload.
     *
     * @throws ConnectionException
     */
    public function receiveSftp(): string;
}