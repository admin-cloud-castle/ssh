<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Contract;

/**
 * Пул idle SSH-сессий.
 */
interface SessionPoolInterface
{
    /**
     * Берёт живую сессию из пула или создаёт новую.
     */
    public function acquire(
        string $endpoint,
        AuthCredentials $auth,
        ?ConnectionOptions $options = null,
    ): SessionInterface;

    /**
     * Возвращает сессию в пул (или закрывает при переполнении).
     */
    public function release(SessionInterface $session): void;

    /**
     * Закрывает все idle-сессии.
     */
    public function drain(): void;

    /**
     * Число idle-сессий.
     */
    public function size(): int;
}
