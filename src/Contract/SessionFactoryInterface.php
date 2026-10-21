<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Contract;

/**
 * Фабрика SSH-сессий: TCP connect, handshake, auth и проверка host key.
 */
interface SessionFactoryInterface
{
    /**
     * Устанавливает TCP-соединение, выполняет SSH handshake и аутентификацию.
     *
     * @param string                 $endpoint Хост[:порт], `[ipv6]:port` или URL `схема://host:port`
     * @param AuthCredentials        $auth     Учётные данные password или publickey
     * @param ConnectionOptions|null $options  Опции таймаутов и security; null — defaults()
     *
     * @return SessionInterface Готовая к exec/SCP/subsystem сессия
     *
     * @throws \RuntimeException При ошибке TCP, SSRF-guard, host key, протокола или auth
     */
    public function connect(
        string $endpoint,
        AuthCredentials $auth,
        ?ConnectionOptions $options = null,
    ): SessionInterface;
}
