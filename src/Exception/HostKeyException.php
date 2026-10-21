<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Exception;

/**
 * Host key не совпал с доверенным списком или нарушена строгая политика проверки.
 */
final class HostKeyException extends SshException
{
}
