<?php

declare(strict_types=1);

namespace CloudCastle\Ssh;

use CloudCastle\Ssh\Contract\SshInterface;
use CloudCastle\Ssh\Exception\SshException;

/**
 * Точка входа пакета Ssh.
 *
 * Заглушка скаффолда: замените на реальный публичный API.
 */
final class Ssh implements SshInterface
{
    /**
     * Версия пакета для диагностики.
     */
    public const VERSION = '0.1.0';

    /**
     * Возвращает идентификатор пакета Composer.
     */
    #[\Override]
    public function name(): string
    {
        return 'cloud-castle/ssh';
    }

    /**
     * Завершает работу с ошибкой пакета.
     *
     * @throws SshException
     */
    public function abort(string $message): never
    {
        throw new SshException($message);
    }
}
