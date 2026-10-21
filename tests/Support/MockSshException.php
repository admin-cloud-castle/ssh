<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Support;

use RuntimeException;

/**
 * Ошибка тестового mock SSH peer/server (не generic RuntimeException).
 */
final class MockSshException extends RuntimeException
{
}
