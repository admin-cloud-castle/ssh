<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Tools\PackageMemoryMeter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-проверки изоляции замера памяти пакета.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
#[CoversClass(PackageMemoryMeter::class)]
final class PackageMemoryMeterTest extends TestCase
{
    /**
     * Hold массива байт даёт package_bytes > baseline и leak ≈ 0 после release.
     */
    public function testMeasureHoldReportsPackageBytesAndLowLeak(): void
    {
        $result = PackageMemoryMeter::measureHold(static fn (): string => str_repeat('x', 1024), 50, 5);

        self::assertSame(50, $result['iterations']);
        self::assertGreaterThan(10_000, $result['package_bytes']);
        self::assertSame(0, $result['leak_bytes']);
    }

    /**
     * Пустой churn не должен давать ложную «утечку» выше шума baseline.
     */
    public function testMeasureChurnEmptyWorkloadNearZero(): void
    {
        $result = PackageMemoryMeter::measureChurn(static function (): void {
            // no-op
        }, 100, 10);

        self::assertSame(0, $result['package_bytes']);
        self::assertSame(0, $result['leak_bytes']);
    }

    /**
     * Baseline пустого цикла неотрицателен.
     */
    public function testEmptyLoopDeltaNonNegative(): void
    {
        self::assertGreaterThanOrEqual(0, PackageMemoryMeter::emptyLoopDelta(200));
    }
}
