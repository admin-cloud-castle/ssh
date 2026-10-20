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
     * @throws \CloudCastle\Ssh\Exception\ConnectionException
     * @throws \CloudCastle\Ssh\Exception\HostKeyException
     * @throws \CloudCastle\Ssh\Exception\SecurityException
     */
    public function connect(
        string $endpoint,
        AuthCredentials $auth,
        ?ConnectionOptions $options = null,
        bool $sftp = false,
    ): SessionInterface;
}