<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Exception;

/**
 * Истёк таймаут ожидания готовности stream (async awaitReadable/awaitWritable).
 */
final class TimeoutException extends SshException
{
}
