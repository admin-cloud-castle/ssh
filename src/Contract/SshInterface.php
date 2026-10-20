<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Contract;

/**
 * Публичный фасад пакета.
 */
interface SshInterface
{
    /**
     * Идентификатор Composer-пакета.
     */
    public function name(): string;

    /**
     * Синхронное подключение.
     */
    public function connect(
        string $endpoint,
        AuthCredentials $auth,
        ?ConnectionOptions $options = null,
    ): SessionInterface;

    /**
     * Фабрика сессий.
     */
    public function factory(): SessionFactoryInterface;

    /**
     * Пул сессий.
     */
    public function pool(): SessionPoolInterface;

    /**
     * Async-рантайм на Fiber.
     */
    public function async(): AsyncRuntimeInterface;
}
