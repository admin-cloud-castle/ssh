<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Contract;

/**
 * Активная SSH-2 сессия после identification, KEX и успешной userauth.
 */
interface SessionInterface
{
    /**
     * Проверяет, что сессия ещё подключена (не вызывался disconnect).
     *
     * @return bool true до disconnect(), иначе false
     */
    public function isConnected(): bool;

    /**
     * Отправляет SSH_MSG_DISCONNECT (если возможно) и закрывает транспорт.
     *
     * Повторный вызов безопасен (no-op).
     */
    public function disconnect(): void;

    /**
     * Выполняет remote command через session-канал и запрос «exec».
     *
     * @param string $command Команда оболочки на удалённой стороне (как есть, без локального shell)
     *
     * @return string Объединённый stdout/данные канала до EOF/CLOSE
     *
     * @throws \RuntimeException При закрытой сессии или ошибке канала/транспорта
     */
    public function exec(string $command): string;

    /**
     * Пишет байты в открытый session-канал (CHANNEL_DATA).
     *
     * При отсутствии канала открывает session-канал автоматически.
     *
     * @param string $payload Байты для передачи на удалённую сторону
     *
     * @throws \RuntimeException При закрытой сессии или ошибке записи
     */
    public function write(string $payload): void;

    /**
     * Читает до $maxLength байт из буфера session-канала.
     *
     * @param int $maxLength Максимум байт; значение ≤0 трактуется реализацией как минимум 1
     *
     * @return string Фрагмент буфера или пустая строка, если данных нет
     *
     * @throws \RuntimeException При закрытой сессии
     */
    public function read(int $maxLength = 8192): string;

    /**
     * Открывает session-канал и запрашивает SSH subsystem (например «sftp»).
     *
     * Повторный вызов с тем же именем при уже открытом канале — no-op.
     *
     * @param string $name Имя subsystem (непустая строка)
     *
     * @throws \RuntimeException При пустом имени, закрытой сессии или отказе сервера
     */
    public function openSubsystem(string $name): void;

    /**
     * Возвращает SHA-256 fingerprint host key в base64 без префикса «SHA256:».
     *
     * @return string Non-empty base64 fingerprint после KEX
     */
    public function hostKeyFingerprint(): string;

    /**
     * Возвращает удалённый endpoint, с которым ассоциирована сессия.
     *
     * @return string Исходный endpoint connect или значение после bindEndpoint()
     */
    public function remoteEndpoint(): string;
}
