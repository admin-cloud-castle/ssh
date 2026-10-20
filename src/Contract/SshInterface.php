<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Contract;

/**
 * Контракт публичного API пакета Ssh.
 */
interface SshInterface
{
    /**
     * Возвращает идентификатор пакета Composer.
     */
    public function name(): string;
}
