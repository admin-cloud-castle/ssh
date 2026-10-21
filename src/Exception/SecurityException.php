<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Exception;

/**
 * Нарушение политики безопасности: SSRF-guard, DNS-rebinding, запрещённый endpoint.
 */
final class SecurityException extends SshException
{
}
