<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Exception;

/**
 * Ошибка USERAUTH: отказ сервера, пустые credentials или неожиданный тип ответа.
 */
final class AuthenticationException extends SshException
{
}
