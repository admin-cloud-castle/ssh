<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Process\ProcessPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

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
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.ExitExpression")
 */
#[CoversClass(ProcessPool::class)]
final class ProcessPoolTest extends TestCase
{
    public function testDefaultConstructorOptions(): void
    {
        $pool = new ProcessPool();
        $workers = new ReflectionProperty(ProcessPool::class, 'workers');
        $sequential = new ReflectionProperty(ProcessPool::class, 'forceSequential');
        self::assertSame(2, $workers->getValue($pool));
        self::assertFalse($sequential->getValue($pool));
    }

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

    public function testMapForkReturnsZeroAndFalse(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork недоступен');
        }

        $pool = new ProcessPool(1, false);
        $out = iterator_to_array(
            $pool->map([0, 1], static fn (int $value): int|false => $value === 0 ? 0 : false),
            false,
        );
        sort($out);
        self::assertSame([0, false], $out);
    }

    public function testFunctionExistsWrapperDelegatesToPhp(): void
    {
        $method = new ReflectionMethod(ProcessPool::class, 'functionExists');
        $pool = new ProcessPool(1, true);
        self::assertTrue($method->invoke($pool, 'strlen'));
        self::assertFalse($method->invoke($pool, 'cloudcastle_ssh_missing_function'));
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
        $pool = new ProcessPool(2, true);
        $expected = function_exists('pcntl_fork') && function_exists('pcntl_waitpid');
        self::assertSame($expected, $method->invoke($pool));
    }

    public function testMapForkUsesWorkerProcesses(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork недоступен');
        }

        $pidFile = tempnam(sys_get_temp_dir(), 'ccpoolpid');
        self::assertNotFalse($pidFile);
        $parentPid = getmypid();
        $pool = new ProcessPool(2, false);

        try {
            iterator_to_array(
                $pool->map([1, 2, 3, 4], static function (int $value) use ($pidFile): int {
                    $workerPid = getmypid();
                    self::assertNotFalse($workerPid);
                    file_put_contents($pidFile, sprintf("%d\n", $workerPid), FILE_APPEND);

                    return $value;
                }),
                false,
            );
            $lines = file($pidFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($lines);
            $unique = array_values(array_unique($lines));
            self::assertGreaterThan(1, count($unique));
            self::assertNotContains((string) $parentPid, $unique);
        } finally {
            if (is_file($pidFile)) {
                unlink($pidFile);
            }
        }
    }

    public function testMapForkWithSlowWorkersCompletes(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork недоступен');
        }

        $pool = new ProcessPool(1, false);
        $out = iterator_to_array(
            $pool->map([1, 2, 3], static function (int $value): int {
                usleep(50_000);

                return $value * 10;
            }),
            false,
        );
        sort($out);
        self::assertSame([10, 20, 30], $out);
    }

    public function testMapForkRespectsWorkerConcurrencyLimit(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork недоступен');
        }

        $stateFile = tempnam(sys_get_temp_dir(), 'ccpoolconc');
        self::assertNotFalse($stateFile);
        file_put_contents($stateFile, "0\n0\n");
        $pool = new ProcessPool(2, false);

        try {
            iterator_to_array(
                $pool->map([1, 2, 3, 4, 5, 6], static function (int $value) use ($stateFile): int {
                    $handle = fopen($stateFile, 'c+');

                    if ($handle !== false) {
                        flock($handle, LOCK_EX);
                        $rawLines = file($stateFile, FILE_IGNORE_NEW_LINES);
                        $lines = is_array($rawLines) && isset($rawLines[0], $rawLines[1])
                            ? $rawLines
                            : ['0', '0'];
                        $active = (int) $lines[0] + 1;
                        $peak = max((int) $lines[1], $active);
                        ftruncate($handle, 0);
                        rewind($handle);
                        fwrite($handle, $active . "\n" . $peak . "\n");
                        fflush($handle);
                        flock($handle, LOCK_UN);
                        fclose($handle);
                    }

                    usleep(80_000);

                    $handle = fopen($stateFile, 'c+');

                    if ($handle !== false) {
                        flock($handle, LOCK_EX);
                        $rawLines = file($stateFile, FILE_IGNORE_NEW_LINES);
                        $lines = is_array($rawLines) && isset($rawLines[0], $rawLines[1])
                            ? $rawLines
                            : ['0', '0'];
                        $active = max(0, (int) $lines[0] - 1);
                        $peak = (int) $lines[1];
                        ftruncate($handle, 0);
                        rewind($handle);
                        fwrite($handle, $active . "\n" . $peak . "\n");
                        fflush($handle);
                        flock($handle, LOCK_UN);
                        fclose($handle);
                    }

                    return $value;
                }),
                false,
            );

            $lines = file($stateFile, FILE_IGNORE_NEW_LINES);
            self::assertIsArray($lines);
            $peak = (int) ($lines[1] ?? 0);
            self::assertGreaterThanOrEqual(1, $peak);
            self::assertLessThanOrEqual(2, $peak);
        } finally {
            if (is_file($stateFile)) {
                unlink($stateFile);
            }
        }
    }

    public function testConcatBodyAppendsChunks(): void
    {
        $method = new ReflectionMethod(ProcessPool::class, 'concatBody');
        $pool = new ProcessPool(1, true);
        self::assertSame('ab', $method->invoke($pool, 'a', 'b'));
        self::assertSame('xyz', $method->invoke($pool, 'xy', 'z'));
    }

    public function testReadWorkerPayloadRejectsZeroLength(): void
    {
        $pool = new ProcessPool(1, true);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$left, $right] = $pair;
        fwrite($left, pack('N', 0));
        fclose($left);
        self::assertSame('', $pool->readWorkerPayload($right));
        fclose($right);
    }

    public function testWriteWorkerResultPrefixesLengthHeader(): void
    {
        $pool = new ProcessPool(1, true);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$left, $right] = $pair;
        $pool->writeWorkerResult($left, 'AB');
        fclose($left);
        $raw = stream_get_contents($right);
        fclose($right);
        self::assertIsString($raw);
        self::assertSame(pack('N', 4) . '"AB"', $raw);
    }

    public function testMapForkSkipsInvalidJsonPayload(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork недоступен');
        }

        $pool = new ProcessPool(1, false);
        $method = new ReflectionMethod(ProcessPool::class, 'reapFinished');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$parent, $child] = $pair;
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);

        if ($pid === 0) {
            fclose($parent);
            fwrite($child, pack('N', 3) . '{x}');
            fclose($child);
            exit(0);
        }

        fclose($child);
        /** @var array<int, resource> $sockets */
        $sockets = [$pid => $parent];
        $deadline = microtime(true) + 2.0;
        $yielded = [];
        $socketCount = count($sockets);

        while ($socketCount > 0 && microtime(true) < $deadline) {
            $args = [&$sockets];
            $reaped = $method->invokeArgs($pool, $args);
            self::assertInstanceOf(\Generator::class, $reaped);
            $batch = iterator_to_array($reaped, false);
            $yielded = [...$yielded, ...$batch];

            self::assertIsArray($sockets);
            $socketCount = count($sockets);
            usleep(5_000);
        }

        self::assertSame([], $yielded);
        self::assertSame(0, $socketCount);
    }

    public function testMapForkIgnoresUnrelatedFinishedChild(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork недоступен');
        }

        $stray = pcntl_fork();
        self::assertNotSame(-1, $stray);

        if ($stray === 0) {
            usleep(30_000);
            exit(0);
        }

        $pool = new ProcessPool(1, false);
        $out = iterator_to_array(
            $pool->map([7, 8], static fn (int $value): int => $value),
            false,
        );
        sort($out);
        self::assertSame([7, 8], $out);
        pcntl_waitpid($stray, $status);
    }

    public function testMapForkRespectsWorkerConcurrency(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork недоступен');
        }

        $peakFile = tempnam(sys_get_temp_dir(), 'ccpoolpeak');
        $activeFile = tempnam(sys_get_temp_dir(), 'ccpoolact');
        self::assertNotFalse($peakFile);
        self::assertNotFalse($activeFile);
        file_put_contents($peakFile, "0\n");
        file_put_contents($activeFile, "0\n");
        $pool = new ProcessPool(1, false);

        try {
            $out = iterator_to_array(
                $pool->map([1, 2, 3, 4], static function (int $value) use ($peakFile, $activeFile): int {
                    $active = fopen($activeFile, 'c+');
                    self::assertNotFalse($active);
                    flock($active, LOCK_EX);
                    $current = (int) trim((string) stream_get_contents($active));
                    $next = $current + 1;
                    ftruncate($active, 0);
                    rewind($active);
                    fwrite($active, (string) $next);
                    fflush($active);
                    $peak = fopen($peakFile, 'c+');
                    self::assertNotFalse($peak);
                    flock($peak, LOCK_EX);
                    $peakValue = (int) trim((string) stream_get_contents($peak));

                    if ($next > $peakValue) {
                        ftruncate($peak, 0);
                        rewind($peak);
                        fwrite($peak, (string) $next);
                        fflush($peak);
                    }

                    flock($peak, LOCK_UN);
                    fclose($peak);
                    flock($active, LOCK_UN);
                    fclose($active);
                    usleep(60_000);
                    $active = fopen($activeFile, 'c+');
                    self::assertNotFalse($active);
                    flock($active, LOCK_EX);
                    $current = (int) trim((string) stream_get_contents($active));
                    ftruncate($active, 0);
                    rewind($active);
                    fwrite($active, (string) max(0, $current - 1));
                    fflush($active);
                    flock($active, LOCK_UN);
                    fclose($active);

                    return $value;
                }),
                false,
            );
            sort($out);
            self::assertSame([1, 2, 3, 4], $out);
            self::assertSame(1, (int) trim((string) file_get_contents($peakFile)));
        } finally {
            if (is_file($peakFile)) {
                unlink($peakFile);
            }

            if (is_file($activeFile)) {
                unlink($activeFile);
            }
        }
    }

    public function testForceSequentialNeverForks(): void
    {
        $pool = new ProcessPool(4, true);
        $parentPid = getmypid();
        $pids = [];
        iterator_to_array(
            $pool->map([1, 2, 3], static function (int $value) use (&$pids): int {
                $pids[] = getmypid();

                return $value;
            }),
            false,
        );
        self::assertSame([$parentPid, $parentPid, $parentPid], $pids);
    }
}
