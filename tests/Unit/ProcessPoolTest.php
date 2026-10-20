<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Process\ProcessPool;
use PHPUnit\Framework\Attributes\CoversClass;
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
#[CoversClass(ProcessPool::class)]
final class ProcessPoolTest extends TestCase
{
    public function testMapEmpty(): void
    {
        $pool = new ProcessPool(2);
        $out = iterator_to_array($pool->map([], static fn (mixed $x): mixed => $x), false);
        self::assertSame([], $out);
    }

    public function testMapSequentialPreservesOrder(): void
    {
        $pool = new ProcessPool(4, true);
        $out = iterator_to_array(
            $pool->map([10, 20, 30], static fn (int $value): int => $value + 1),
            false,
        );
        self::assertSame([11, 21, 31], $out);
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

    public function testMapForkReturnsSerializableResults(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork недоступен');
        }

        $pool = new ProcessPool(2, false);
        $out = iterator_to_array(
            $pool->map(['a', 'b'], static fn (string $value): array => ['key' => $value]),
            false,
        );
        sort($out);
        self::assertSame([['key' => 'a'], ['key' => 'b']], $out);
    }

    public function testWorkersZeroStillCompletesForkedMap(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork недоступен');
        }

        $pool = new ProcessPool(0, false);
        $out = iterator_to_array(
            $pool->map([1, 2], static fn (int $value): int => $value + 10),
            false,
        );
        sort($out);
        self::assertSame([11, 12], $out);
    }

    public function testWriteAndReadWorkerPayloadRoundTrip(): void
    {
        $pool = new ProcessPool(1, true);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$left, $right] = $pair;
        $pool->writeWorkerResult($left, ['ok' => 1, 'n' => 42]);
        fclose($left);
        $body = $pool->readWorkerPayload($right);
        fclose($right);
        self::assertSame(['ok' => 1, 'n' => 42], json_decode($body, true));
    }

    public function testReadWorkerPayloadShortHeader(): void
    {
        $pool = new ProcessPool(1, true);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$left, $right] = $pair;
        fwrite($left, 'ab');
        fclose($left);
        self::assertSame('', $pool->readWorkerPayload($right));
        fclose($right);
    }

    public function testReadWorkerPayloadExactBodyLength(): void
    {
        $pool = new ProcessPool(1, true);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$left, $right] = $pair;
        $raw = str_repeat('Z', 4096);
        fwrite($left, pack('N', strlen($raw)) . $raw);
        fclose($left);
        self::assertSame($raw, $pool->readWorkerPayload($right));
        fclose($right);
    }

    public function testReadWorkerPayloadTruncatedBody(): void
    {
        $pool = new ProcessPool(1, true);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$left, $right] = $pair;
        fwrite($left, pack('N', 10) . 'abc');
        fclose($left);
        self::assertSame('abc', $pool->readWorkerPayload($right));
        fclose($right);
    }

    public function testReadWorkerPayloadStopsOnEmptyChunk(): void
    {
        $pool = new ProcessPool(1, true);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$left, $right] = $pair;
        fwrite($left, pack('N', 100));
        fclose($left);
        $partial = $pool->readWorkerPayload($right);
        fclose($right);
        self::assertSame('', $partial);
    }

    public function testSupportsForkReflectsExtensions(): void
    {
        $method = new ReflectionMethod(ProcessPool::class, 'supportsFork');
        $method->setAccessible(true);
        $pool = new ProcessPool(2, true);
        $expected = function_exists('pcntl_fork') && function_exists('pcntl_waitpid');
        self::assertSame($expected, $method->invoke($pool));
    }
}
