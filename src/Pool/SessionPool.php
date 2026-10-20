<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Pool;

use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Contract\SessionFactoryInterface;
use CloudCastle\Ssh\Contract\SessionInterface;
use CloudCastle\Ssh\Contract\SessionPoolInterface;

/**
 * Пул idle SSH-сессий с ключом по lowercase(endpoint) и лимитом на ключ.
 */
final class SessionPool implements SessionPoolInterface
{
    /**
     * Максимум idle-сессий на один ключ endpoint (≥1 после нормализации).
     *
     * @var int
     */
    private readonly int $maxPerKey;

    /**
     * Корзины idle-сессий: ключ — lowercase endpoint.
     *
     * @var array<string, list<SessionInterface>>
     */
    private array $idle = [];

    /**
     * Создаёт пул над фабрикой сессий.
     *
     * @param SessionFactoryInterface $factory Для connect при пустом/протухшем idle
     * @param int                     $maxPerKey Максимум idle на ключ; ≤0 заменяется на 8
     */
    public function __construct(
        /** Фабрика для создания новых сессий. @var SessionFactoryInterface */
        private readonly SessionFactoryInterface $factory,
        int $maxPerKey = 8,
    ) {
        $this->maxPerKey = $maxPerKey > 0 ? $maxPerKey : 8;
    }

    /**
     * Берёт живую сессию из idle или создаёт новую через фабрику.
     *
     * @param string                 $endpoint Цель и ключ пула
     * @param AuthCredentials        $auth     Credentials для новой сессии
     * @param ConnectionOptions|null $options  Опции для новой сессии
     *
     * @return SessionInterface Подключённая сессия
     */
    #[\Override]
    public function acquire(
        string $endpoint,
        AuthCredentials $auth,
        ?ConnectionOptions $options = null,
    ): SessionInterface {
        $key = $this->key($endpoint);

        while (isset($this->idle[$key]) && $this->idle[$key] !== []) {
            $session = array_pop($this->idle[$key]);

            if ($session->isConnected()) {
                return $session;
            }

            $session->disconnect();
        }

        return $this->factory->connect($endpoint, $auth, $options);
    }

    /**
     * Возвращает сессию в idle или закрывает при переполнении корзины.
     *
     * @param SessionInterface $session Сессия после использования вызывающим кодом
     */
    #[\Override]
    public function release(SessionInterface $session): void
    {
        if (!$session->isConnected()) {
            return;
        }

        $key = $this->key($session->remoteEndpoint());
        $bucket = $this->idle[$key] ?? [];

        if (count($bucket) >= $this->maxPerKey) {
            $session->disconnect();

            return;
        }

        $bucket[] = $session;
        $this->idle[$key] = $bucket;
    }

    /**
     * Закрывает все idle-сессии и очищает внутренние корзины.
     */
    #[\Override]
    public function drain(): void
    {
        foreach ($this->idle as $bucket) {
            foreach ($bucket as $session) {
                $session->disconnect();
            }
        }

        $this->idle = [];
    }

    /**
     * Возвращает суммарное число idle-сессий.
     *
     * @return int Целое ≥0
     */
    #[\Override]
    public function size(): int
    {
        $total = 0;

        foreach ($this->idle as $bucket) {
            $total += count($bucket);
        }

        return $total;
    }

    /**
     * Нормализует endpoint в ключ пула (нижний регистр).
     *
     * @param string $endpoint Исходный endpoint
     *
     * @return string Ключ корзины
     */
    private function key(string $endpoint): string
    {
        return strtolower($endpoint);
    }
}
