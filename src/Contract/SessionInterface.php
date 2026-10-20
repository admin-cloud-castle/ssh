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
     * @throws \RuntimeException При ошибке канала или транспорта
     */
    public function exec(string $command): string;

    /**
     * Пишет в session-канал.
     *
     * @throws \RuntimeException При закрытой сессии или ошибке записи
     */
    public function write(string $payload): void;

    /**
     * Читает из буфера session-канала.
     *
     * @throws \RuntimeException При закрытой сессии
     */
    public function read(int $maxLength = 8192): string;

    /**
     * Открывает session-канал и запрашивает SSH subsystem (например «sftp» из отдельного пакета).
     *
     * @throws \RuntimeException При ошибке канала
     */
    public function openSubsystem(string $name): void;

    /**
     * SHA256 fingerprint host key (base64 без префикса).
     */
    public function hostKeyFingerprint(): string;

    /**
     * Удалённый endpoint сессии.
     */
    public function remoteEndpoint(): string;
}
