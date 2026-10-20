<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Contract;

/**
 * Пул idle SSH-сессий с ключом по endpoint (повторное использование соединений).
 */
interface SessionPoolInterface
{
    /**
     * Берёт живую сессию из idle-пула или создаёт новую через фабрику.
     *
     * Мёртвые (isConnected=false) сессии из пула отбрасываются с disconnect().
     *
     * @param string                 $endpoint Ключ пула и цель connect
     * @param AuthCredentials        $auth     Credentials для новой сессии
     * @param ConnectionOptions|null $options  Опции для новой сессии; null — defaults фабрики
     *
     * @return SessionInterface Подключённая сессия (из пула или только что созданная)
     *
     * @throws \RuntimeException При ошибке создания новой сессии
     */
    public function acquire(
        string $endpoint,
        AuthCredentials $auth,
        ?ConnectionOptions $options = null,
    ): SessionInterface;

    /**
     * Возвращает сессию в idle-пул или закрывает её при переполнении лимита.
     *
     * Закрытая сессия (isConnected=false) игнорируется.
     *
     * @param SessionInterface $session Сессия для возврата в пул
     */
    public function release(SessionInterface $session): void;

    /**
     * Закрывает все idle-сессии и очищает пул.
     */
    public function drain(): void;

    /**
     * Возвращает суммарное число сессий во всех idle-корзинах.
     *
     * @return int Целое ≥0
     */
    public function size(): int;
}
