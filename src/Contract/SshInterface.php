<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Contract;

/**
 * Публичный фасад пакета cloud-castle/ssh: подключение, фабрика, пул и async-рантайм.
 */
interface SshInterface
{
    /**
     * Возвращает Composer-имя пакета (`cloud-castle/ssh`).
     *
     * @return string Стабильный идентификатор пакета
     */
    public function name(): string;

    /**
     * Устанавливает синхронное SSH-соединение к endpoint.
     *
     * @param string                  $endpoint Хост[:порт], `[ipv6]:port` или URL вида `tcp://host:22`
     * @param AuthCredentials         $auth     Password или publickey credentials
     * @param ConnectionOptions|null  $options  Опции connect/I/O и security; null — defaults()
     *
     * @return SessionInterface Активная сессия после handshake и auth
     *
     * @throws \RuntimeException При ошибке TCP, протокола, auth, host key или SSRF-guard
     */
    public function connect(
        string $endpoint,
        AuthCredentials $auth,
        ?ConnectionOptions $options = null,
    ): SessionInterface;

    /**
     * Возвращает фабрику SSH-сессий (ленивая инициализация во фасаде).
     *
     * @return SessionFactoryInterface Фабрика для прямого connect без пула
     */
    public function factory(): SessionFactoryInterface;

    /**
     * Возвращает пул idle-сессий, привязанный к фабрике фасада.
     *
     * @return SessionPoolInterface Пул с ключом по endpoint
     */
    public function pool(): SessionPoolInterface;

    /**
     * Возвращает кооперативный async-рантайм на Fiber + stream_select.
     *
     * @return AsyncRuntimeInterface Рантайм для awaitReadable/awaitWritable
     */
    public function async(): AsyncRuntimeInterface;
}
