<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Process\ProcessPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
#[CoversClass(ProcessPool::class)]
final class ProcessPoolTest extends TestCase
{
    public function testMapEmpty(): void
    {
        $pool = new ProcessPool(2);
        $out = iterator_to_array($pool->map([], static fn (mixed $x): mixed => $x), false);
        self::assertSame([], $out);
    }

    public function testMapSequentialFallbackAndFork(): void
    {
        $pool = new ProcessPool(2);
        $out = iterator_to_array(
            $pool->map([1, 2, 3], static fn (int $value): int => $value * 2),
            false,
        );
        sort($out);
        self::assertSame([2, 4, 6], $out);
    }
}
