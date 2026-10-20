<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Exception;

/**
 * Блокировка SSRF/DNS-rebinding или иной политики безопасности.
 */
final class SecurityException extends SshException
{
}
