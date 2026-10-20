<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Async\FiberAsyncRuntime;
use CloudCastle\Ssh\Async\NativeStreamSelector;
use CloudCastle\Ssh\Exception\TimeoutException;
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
#[CoversClass(FiberAsyncRuntime::class)]
#[CoversClass(NativeStreamSelector::class)]
final class AsyncRuntimeTest extends TestCase
{
    public function testRunReturnsValue(): void
    {
        $runtime = new FiberAsyncRuntime();
        $result = $runtime->run(static fn (): int => 42);
        self::assertSame(42, $result);
    }

    public function testAwaitReadableMemoryStream(): void
    {
        $runtime = new FiberAsyncRuntime();
        $stream = fopen('php://memory', 'r+b');
        self::assertNotFalse($stream);
        fwrite($stream, 'x');
        rewind($stream);
        $runtime->run(static function () use ($runtime, $stream): void {
            $runtime->awaitReadable($stream, 0.1);
        });
        $contents = stream_get_contents($stream, -1, 0);
        self::assertSame('x', is_string($contents) ? $contents : '');
        fclose($stream);
    }

    public function testAwaitWritableMemoryStream(): void
    {
        $runtime = new FiberAsyncRuntime();
        $stream = fopen('php://memory', 'r+b');
        self::assertNotFalse($stream);
        $runtime->run(static function () use ($runtime, $stream): void {
            $runtime->awaitWritable($stream, 0.1);
        });
        $written = fwrite($stream, 'ok');
        self::assertSame(2, $written);
        fclose($stream);
    }

    public function testInvalidStreamThrows(): void
    {
        $runtime = new FiberAsyncRuntime(new NativeStreamSelector());
        $this->expectException(TimeoutException::class);
        $runtime->awaitReadable('not-a-stream', 0.01);
    }
}
