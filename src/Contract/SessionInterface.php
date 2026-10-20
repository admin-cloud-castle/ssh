<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Contract;

/**
 * Активная SSH-2 сессия после handshake и auth.
 */
interface SessionInterface
{
    /**
     * Сессия ещё подключена.
     */
    public function isConnected(): bool;

    /**
     * Отправляет DISCONNECT и закрывает транспорт.
     */
    public function disconnect(): void;

    /**
     * Выполняет remote command через channel «exec».
     *
     * @throws \CloudCastle\Ssh\Exception\ConnectionException
     */
    public function exec(string $command): string;

    /**
     * Пишет в session-канал.
     *
     * @throws \CloudCastle\Ssh\Exception\ConnectionException
     */
    public function write(string $payload): void;

    /**
     * Читает из буфера session-канала.
     *
     * @throws \CloudCastle\Ssh\Exception\ConnectionException
     */
    public function read(int $maxLength = 8192): string;

    /**
     * Открывает SFTP subsystem.
     *
     * @throws \CloudCastle\Ssh\Exception\ConnectionException
     */
    public function openSftp(): SftpClientInterface;

    /**
     * SHA256 fingerprint host key (base64 без префикса).
     */
    public function hostKeyFingerprint(): string;

    /**
     * Удалённый endpoint сессии.
     */
    public function remoteEndpoint(): string;
}