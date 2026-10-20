<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Support;

/**
 * Поднимает MockSshServer в отдельном PHP-процессе по TCP (без fork — дружит с coverage).
 *
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
final class MockSshPeer
{
    /**
     * Стартует peer и сразу подключается клиентом.
     *
     * @return array{0: resource, 1: resource} [clientStream, process]
     */
    public static function start(): array
    {
        [$port, $process] = self::listen();
        $stream = self::connect($port);

        return [$stream, $process];
    }

    /**
     * Только слушает порт — клиент подключается сам (для SessionFactory).
     *
     * @return array{0: int, 1: resource} [port, process]
     */
    public static function listen(): array
    {
        $portFile = tempnam(sys_get_temp_dir(), 'ccssh');

        if ($portFile === false) {
            throw new \RuntimeException('tempnam failed');
        }

        $script = __DIR__ . '/mock-ssh-tcp.php';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($portFile);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__, 2));

        if (!is_resource($process)) {
            throw new \RuntimeException('proc_open mock server failed');
        }

        return [self::waitPort($portFile), $process];
    }

    /**
     * @return resource
     */
    public static function connect(int $port)
    {
        $stream = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 5.0);

        if ($stream === false) {
            throw new \RuntimeException(sprintf('connect mock: [%d] %s', $errno, $errstr));
        }

        stream_set_timeout($stream, 5);

        return $stream;
    }

    /**
     * @param resource $process
     */
    public static function stop(mixed $process): void
    {
        if (is_resource($process)) {
            $status = proc_get_status($process);

            if (is_array($status) && ($status['running'] ?? false)) {
                proc_terminate($process);
            }

            proc_close($process);
        }
    }

    private static function waitPort(string $portFile): int
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            if (is_file($portFile)) {
                $raw = trim((string) file_get_contents($portFile));

                if ($raw !== '' && ctype_digit($raw)) {
                    @unlink($portFile);

                    return (int) $raw;
                }
            }

            usleep(20_000);
        }

        throw new \RuntimeException('mock server port timeout');
    }
}
