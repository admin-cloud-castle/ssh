<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Async\FiberAsyncRuntime;
use CloudCastle\Ssh\Async\NativeStreamSelector;
use CloudCastle\Ssh\Exception\TimeoutException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        $runtime->run(static function () use ($runtime, $stream): void {
            $runtime->awaitReadable($stream, 0.1);
        });
        fclose($stream);
        $this->addToAssertionCount(1);
    }

    public function testAwaitWritableMemoryStream(): void
    {
        $runtime = new FiberAsyncRuntime();
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        $runtime->run(static function () use ($runtime, $stream): void {
            $runtime->awaitWritable($stream, 0.1);
        });
        fclose($stream);
        $this->addToAssertionCount(1);
    }

    public function testInvalidStreamThrows(): void
    {
        $runtime = new FiberAsyncRuntime(new NativeStreamSelector());
        $this->expectException(TimeoutException::class);
        $runtime->awaitReadable('not-a-stream', 0.01);
    }
}