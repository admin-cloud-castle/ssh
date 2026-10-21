<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Exception;

use RuntimeException;

/**
 * Базовое исключение пакета cloud-castle/ssh.
 *
 * Все доменные ошибки SSH наследуют этот класс.
 */
class SshException extends RuntimeException
{
}
