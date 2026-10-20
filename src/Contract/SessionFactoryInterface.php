<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Contract;

/**
 * Фабрика SSH-сессий.
 */
interface SessionFactoryInterface
{
    /**
     * Устанавливает TCP + SSH handshake + auth.
     *
     * @throws \RuntimeException При ошибке TCP, host key или SSRF-guard
     */
    public function connect(
        string $endpoint,
        AuthCredentials $auth,
        ?ConnectionOptions $options = null,
    ): SessionInterface;
}
