<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Process\ProcessPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProcessPool::class)]
final class ProcessPoolTest extends TestCase
{
    public function testMapEmpty(): void
    {
        $pool = new ProcessPool(2);
        $out = iterator_to_array($pool->map([], static fn ($x) => $x), false);
        self::assertSame([], $out);
    }

    public function testMapSequentialFallbackAndFork(): void
    {
        $pool = new ProcessPool(2);
        $out = iterator_to_array($pool->map([1, 2, 3], static fn (int $x): int => $x * 2), false);
        sort($out);
        self::assertSame([2, 4, 6], $out);
    }
}