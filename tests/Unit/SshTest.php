<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Exception\SshException;
use CloudCastle\Ssh\Ssh;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Базовые тесты фасада (совместимость со скаффолдом).
 */
/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.ShortVariable")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.MissingImport")
 * @SuppressWarnings("PHPMD.ExcessivePublicCount")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 * @SuppressWarnings("PHPMD.UnusedLocalVariable")
 */
#[CoversClass(Ssh::class)]
final class SshTest extends TestCase
{
    public function testNameReturnsComposerPackageName(): void
    {
        $package = new Ssh();

        self::assertSame('cloud-castle/ssh', $package->name());
    }

    public function testAbortThrowsSshException(): void
    {
        $package = new Ssh();

        $this->expectException(SshException::class);
        $this->expectExceptionMessage('тест');

        $package->abort('тест');
    }
}
