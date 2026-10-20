<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Exception\SshException;
use CloudCastle\Ssh\Ssh;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Модульные тесты заглушки Ssh.
 */
#[CoversClass(Ssh::class)]
final class SshTest extends TestCase
{
    /**
     * Проверяет, что name() возвращает composer-имя пакета.
     */
    public function testNameReturnsComposerPackageName(): void
    {
        $package = new Ssh();

        self::assertSame('cloud-castle/ssh', $package->name());
    }

    /**
     * Проверяет, что abort() бросает SshException.
     */
    public function testAbortThrowsSshException(): void
    {
        $package = new Ssh();

        $this->expectException(SshException::class);
        $this->expectExceptionMessage('тест');

        $package->abort('тест');
    }
}
