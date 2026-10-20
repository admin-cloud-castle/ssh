<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Support;

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
     * Pipe peer-процессов по ключу process (fclose только для останавливаемого).
     *
     * @var array<int, list<resource>>
     */
    private static array $pipesByProcess = [];

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
            throw new MockSshException('tempnam failed');
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
            throw new MockSshException('proc_open mock server failed');
        }

        self::$pipesByProcess[self::processKey($process)] = array_values($pipes);

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
            $candidate = @stream_socket_client('tcp://' . '127.0.0.1' . ':' . $port, $errno, $errstr, 1.0);

            if (is_resource($candidate)) {
                stream_set_timeout($candidate, 10);

                return $candidate;
            }

            usleep(50_000);
        }

        throw new MockSshException(sprintf('connect mock: [%d] %s', $errno, $errstr));
    }

    /**
     * Останавливает peer-процесс и закрывает его pipe (без orphan fd).
     *
     * @param resource $process
     */
    public static function stop($process): void
    {
        self::closePipesFor($process);

        $status = proc_get_status($process);

        if ($status['running']) {
            proc_terminate($process);
        }

        proc_close($process);
    }

    /**
     * @param resource $process
     */
    private static function closePipesFor($process): void
    {
        $key = self::processKey($process);

        if (!isset(self::$pipesByProcess[$key])) {
            return;
        }

        foreach (self::$pipesByProcess[$key] as $pipe) {
            fclose($pipe);
        }

        unset(self::$pipesByProcess[$key]);
    }

    /**
     * @param mixed $process proc_open resource или объект процесса
     */
    private static function processKey(mixed $process): int
    {
        if (is_object($process)) {
            return spl_object_id($process);
        }

        if (is_resource($process) && function_exists('get_resource_id')) {
            return get_resource_id($process);
        }

        return 0;
    }

    private static function waitPort(string $portFile): int
    {
        $deadline = microtime(true) + 15.0;

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

        throw new MockSshException('mock server port timeout');
    }

    /**
     * Удаляет файл только внутри sys_get_temp_dir().
     */
    private static function unlinkTempFile(string $path): void
    {
        TempPath::unlinkIfTemp($path);
    }
}
