<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Support;

use RuntimeException;

/**
 * Поднимает MockSshServer в отдельном PHP-процессе по TCP (без fork — дружит с coverage).
 *
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 * @SuppressWarnings("PHPMD.MissingImport")
 * @SuppressWarnings("PHPMD.UnusedLocalVariable")
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
final class MockSshPeer
{
    /**
     * Открытые pipe peer-процессов (удерживают дескрипторы живыми).
     *
     * @var list<list<resource>>
     */
    private static array $retainedPipes = [];

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
            throw new RuntimeException('tempnam failed');
        }

        $script = __DIR__ . '/mock-ssh-tcp.php';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($portFile);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        // Фиксированный PHP-скрипты тестов (не пользовательский ввод).
        // nosemgrep: php.lang.security.exec-use.exec-use, php_exec_rule-exec-use
        $process = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__, 2));

        if ($process === false) {
            throw new RuntimeException('proc_open mock server failed');
        }

        self::$retainedPipes[] = array_values($pipes);

        return [self::waitPort($portFile), $process];
    }

    /**
     * Подключается к peer с короткими ретраями (accept может отставать от port-file).
     *
     * @return resource
     */
    public static function connect(int $port)
    {
        $deadline = microtime(true) + 5.0;
        $errno = 0;
        $errstr = '';

        while (microtime(true) < $deadline) {
            $candidate = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 1.0);

            if (is_resource($candidate)) {
                stream_set_timeout($candidate, 10);

                return $candidate;
            }

            usleep(50_000);
        }

        throw new RuntimeException(sprintf('connect mock: [%d] %s', $errno, $errstr));
    }

    /**
     * Останавливает peer-процесс.
     *
     * @param resource $process
     */
    public static function stop($process): void
    {
        if (self::$retainedPipes !== []) {
            self::$retainedPipes = [];
        }

        $status = proc_get_status($process);

        if ($status['running']) {
            proc_terminate($process);
        }

        proc_close($process);
    }

    private static function waitPort(string $portFile): int
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            if (is_file($portFile)) {
                $raw = trim((string) file_get_contents($portFile));

                if ($raw !== '' && ctype_digit($raw)) {
                    self::unlinkTempFile($portFile);

                    return (int) $raw;
                }
            }

            usleep(20_000);
        }

        throw new RuntimeException('mock server port timeout');
    }

    /**
     * Удаляет файл только внутри sys_get_temp_dir().
     */
    private static function unlinkTempFile(string $path): void
    {
        $tempRoot = realpath(sys_get_temp_dir());
        $real = realpath($path);

        if ($tempRoot === false || $real === false || !str_starts_with($real, $tempRoot)) {
            return;
        }

        unlink($real);
    }
}
