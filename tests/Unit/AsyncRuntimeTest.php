<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Async\FiberAsyncRuntime;
use CloudCastle\Ssh\Async\NativeStreamSelector;
use CloudCastle\Ssh\Exception\TimeoutException;
use CloudCastle\Ssh\Tests\Support\RecordingStreamSelector;
use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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

    public function testConstructorUsesInjectedSelector(): void
    {
        $selector = new RecordingStreamSelector();
        $runtime = new FiberAsyncRuntime($selector);
        $client = $this->openBlockingTcpClient();

        try {
            $runtime->run(static function () use ($runtime, $client): void {
                $runtime->awaitReadable($client, 0.2);
            });
            self::assertNotNull($selector->lastRead);
            self::assertCount(1, $selector->lastRead);
            self::assertSame($client, $selector->lastRead[0]);
        } finally {
            $this->closeTcpClient($client);
        }

        self::assertGreaterThan(0, $selector->callCount);
    }

    public function testAwaitOutsideFiberUsesSelectorDirectly(): void
    {
        $selector = new RecordingStreamSelector();
        $runtime = new FiberAsyncRuntime($selector);
        $client = $this->openBlockingTcpClient();

        try {
            $runtime->awaitReadable($client, 0.15);
            self::assertGreaterThan(0, $selector->callCount);
            self::assertNotNull($selector->lastRead);
            self::assertNull($selector->lastWrite);
        } finally {
            $this->closeTcpClient($client);
        }
    }

    public function testAwaitWritableUsesWriteBucketInFiber(): void
    {
        $selector = new RecordingStreamSelector();
        $runtime = new FiberAsyncRuntime($selector);
        $client = $this->openBlockingTcpClient();

        try {
            $runtime->run(static function () use ($runtime, $client): void {
                $runtime->awaitWritable($client, 0.15);
            });
            self::assertGreaterThan(0, $selector->callCount);
            self::assertNull($selector->lastRead);
            self::assertNotNull($selector->lastWrite);
            self::assertCount(1, $selector->lastWrite);
            self::assertSame($client, $selector->lastWrite[0]);
        } finally {
            $this->closeTcpClient($client);
        }
    }

    public function testAwaitReadableInsideFiberSuspendsBeforeSelect(): void
    {
        $selector = new RecordingStreamSelector();
        $runtime = new FiberAsyncRuntime($selector);
        $client = $this->openBlockingTcpClient();

        try {
            $fiber = new Fiber(static function () use ($runtime, $client): string {
                $runtime->awaitReadable($client, 0.12);

                return 'done';
            });
            $payload = $fiber->start();
            self::assertSame(0, $selector->callCount);
            self::assertIsArray($payload);
            self::assertSame('read', $payload['op']);
            self::assertSame($client, $payload['stream']);

            $wait = new ReflectionMethod(FiberAsyncRuntime::class, 'wait');
            $wait->invoke($runtime, $payload['stream'], $payload['op'], (float) $payload['timeout']);
            self::assertSame(1, $selector->callCount);
            self::assertNotNull($selector->lastRead);
            self::assertSame($client, $selector->lastRead[0]);
        } finally {
            $this->closeTcpClient($client);
        }
    }

    public function testRunInvokesWaitForValidSuspendPayload(): void
    {
        $selector = new RecordingStreamSelector();
        $runtime = new FiberAsyncRuntime($selector);
        $client = $this->openBlockingTcpClient();

        try {
            $runtime->run(static function () use ($runtime, $client): void {
                $runtime->awaitReadable($client, 0.12);
            });
            self::assertGreaterThan(0, $selector->callCount);
        } finally {
            $this->closeTcpClient($client);
        }
    }

    public function testRunWaitCastsIntegerTimeoutToFractionalSeconds(): void
    {
        $selector = new RecordingStreamSelector();
        $runtime = new FiberAsyncRuntime($selector);
        $client = $this->openBlockingTcpClient();

        try {
            $runtime->run(static function () use ($client): void {
                Fiber::suspend([
                    'op' => 'read',
                    'stream' => $client,
                    'timeout' => 1,
                ]);
            });
            self::assertSame(1, $selector->lastSeconds);
            self::assertSame(0, $selector->lastMicroseconds);
        } finally {
            $this->closeTcpClient($client);
        }
    }

    public function testSelectErrorThrowsTimeoutException(): void
    {
        $selector = new RecordingStreamSelector(false);
        $runtime = new FiberAsyncRuntime($selector);
        $client = $this->openBlockingTcpClient();

        try {
            $this->expectException(TimeoutException::class);
            $this->expectExceptionMessage('Ошибка ожидания read');
            $runtime->awaitReadable($client, 0.05);
        } finally {
            $this->closeTcpClient($client);
        }
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidWaitPayloadProvider(): iterable
    {
        yield 'не массив' => [null];
        yield 'нет ключа op' => [['stream' => null, 'timeout' => 0.1]];
        yield 'нет ключа stream' => [['op' => 'read', 'timeout' => 0.1]];
        yield 'нет ключа timeout' => [['op' => 'read', 'stream' => null]];
        yield 'op не строка' => [['op' => 1, 'stream' => null, 'timeout' => 0.1]];
        yield 'timeout строка' => [['op' => 'read', 'stream' => null, 'timeout' => '0.1']];
        yield 'timeout null' => [['op' => 'read', 'stream' => null, 'timeout' => null]];
        yield 'timeout bool' => [['op' => 'read', 'stream' => null, 'timeout' => true]];
    }

    #[DataProvider('invalidWaitPayloadProvider')]
    public function testIsWaitPayloadRejectsInvalid(mixed $payload): void
    {
        $runtime = new FiberAsyncRuntime();
        $method = new ReflectionMethod(FiberAsyncRuntime::class, 'isWaitPayload');
        self::assertFalse($method->invoke($runtime, $payload));
    }

    public function testIsWaitPayloadAcceptsIntTimeout(): void
    {
        $runtime = new FiberAsyncRuntime();
        $method = new ReflectionMethod(FiberAsyncRuntime::class, 'isWaitPayload');
        $stream = fopen('php://memory', 'r+b');
        self::assertNotFalse($stream);

        self::assertTrue($method->invoke($runtime, [
            'op' => 'read',
            'stream' => $stream,
            'timeout' => 2,
        ]));
        fclose($stream);
    }

    public function testIsWaitPayloadAcceptsFloatTimeout(): void
    {
        $runtime = new FiberAsyncRuntime();
        $method = new ReflectionMethod(FiberAsyncRuntime::class, 'isWaitPayload');
        $stream = fopen('php://memory', 'r+b');
        self::assertNotFalse($stream);

        self::assertTrue($method->invoke($runtime, [
            'op' => 'write',
            'stream' => $stream,
            'timeout' => 0.25,
        ]));
        fclose($stream);
    }

    public function testIsWaitPayloadRejectsNonStringOpWithValidTimeout(): void
    {
        $runtime = new FiberAsyncRuntime();
        $method = new ReflectionMethod(FiberAsyncRuntime::class, 'isWaitPayload');
        $stream = fopen('php://memory', 'r+b');
        self::assertNotFalse($stream);

        self::assertFalse($method->invoke($runtime, [
            'op' => ['read'],
            'stream' => $stream,
            'timeout' => 1.0,
        ]));
        fclose($stream);
    }

    public function testSplitTimeoutZero(): void
    {
        $runtime = new FiberAsyncRuntime();
        $method = new ReflectionMethod(FiberAsyncRuntime::class, 'splitTimeout');
        self::assertSame([0, 0], $method->invoke($runtime, 0.0));
    }

    public function testSplitTimeoutNegativeThrows(): void
    {
        $runtime = new FiberAsyncRuntime();
        $method = new ReflectionMethod(FiberAsyncRuntime::class, 'splitTimeout');
        $this->expectException(TimeoutException::class);
        $this->expectExceptionMessage('Отрицательный таймаут ожидания');
        $method->invoke($runtime, -0.5);
    }

    public function testSplitTimeoutUsesRoundForMicroseconds(): void
    {
        $runtime = new FiberAsyncRuntime();
        $method = new ReflectionMethod(FiberAsyncRuntime::class, 'splitTimeout');
        self::assertSame([0, 0], $method->invoke($runtime, 5.0E-8));
        self::assertSame([0, 1], $method->invoke($runtime, 0.0000006));
    }

    public function testSplitTimeoutFractionUsesFloatSubtraction(): void
    {
        $runtime = new FiberAsyncRuntime();
        $method = new ReflectionMethod(FiberAsyncRuntime::class, 'splitTimeout');
        $parts = $method->invoke($runtime, 1.0000004);
        self::assertIsArray($parts);
        self::assertSame([1, 0], $parts);
        self::assertSame([0, 500_000], $method->invoke($runtime, 0.5));
        self::assertSame([2, 0], $method->invoke($runtime, 1.9999995));
    }

    public function testRunAppliesSplitTimeoutFromFloatAwait(): void
    {
        $selector = new RecordingStreamSelector();
        $runtime = new FiberAsyncRuntime($selector);
        $client = $this->openBlockingTcpClient();

        try {
            $runtime->run(static function () use ($runtime, $client): void {
                $runtime->awaitReadable($client, 1.9999995);
            });
            self::assertSame(2, $selector->lastSeconds);
            self::assertSame(0, $selector->lastMicroseconds);
        } finally {
            $this->closeTcpClient($client);
        }
    }

    public function testSplitTimeoutZeroTimeoutPassedToSelector(): void
    {
        $selector = new RecordingStreamSelector(0);
        $runtime = new FiberAsyncRuntime($selector);
        $client = $this->openBlockingTcpClient();

        try {
            $this->expectException(TimeoutException::class);
            $this->expectExceptionMessage('Таймаут ожидания read');
            $runtime->awaitReadable($client, 0.0);
        } finally {
            self::assertSame(0, $selector->lastSeconds);
            self::assertSame(0, $selector->lastMicroseconds);
            $this->closeTcpClient($client);
        }
    }

    public function testNativeStreamSelectorDefaultMicrosecondsParameter(): void
    {
        $method = new ReflectionMethod(NativeStreamSelector::class, 'select');
        $parameters = $method->getParameters();
        self::assertCount(5, $parameters);
        self::assertSame('microseconds', $parameters[4]->getName());
        self::assertTrue($parameters[4]->isDefaultValueAvailable());
        self::assertSame(0, $parameters[4]->getDefaultValue());
    }

    public function testNativeStreamSelectorSelectOmitsFifthArgument(): void
    {
        $selector = new NativeStreamSelector();
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        $read = [$server];
        $write = null;
        $except = null;
        $withDefault = $selector->select($read, $write, $except, 0);
        $read = [$server];
        $explicitZero = $selector->select($read, $write, $except, 0, 0);
        self::assertSame($withDefault, $explicitZero);
        fclose($server);
    }

    /**
     * @return resource
     */
    private function openBlockingTcpClient(): mixed
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        $name = stream_socket_get_name($server, false);
        self::assertIsString($name);
        $colonPos = strrpos($name, ':');
        self::assertNotFalse($colonPos);
        $port = (int) substr($name, $colonPos + 1);
        $client = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 1.0);
        self::assertNotFalse($client);
        stream_set_blocking($client, false);
        $this->registerServerForShutdown($server);

        return $client;
    }

    /** @var list<resource> */
    private array $serversToClose = [];

    /**
     * @param resource $server
     */
    private function registerServerForShutdown(mixed $server): void
    {
        $this->serversToClose[] = $server;
    }

    /**
     * @param resource $client
     */
    private function closeTcpClient(mixed $client): void
    {
        fclose($client);

        while ($this->serversToClose !== []) {
            $server = array_pop($this->serversToClose);
            if (is_resource($server)) {
                fclose($server);
            }
        }
    }
}
