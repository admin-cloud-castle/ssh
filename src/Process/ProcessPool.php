<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Process;

/**
 * Пул worker-процессов (pcntl_fork) для CPU-bound задач поверх SSH-операций.
 *
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
 * @SuppressWarnings("PHPMD.NPathComplexity")
 * @SuppressWarnings("PHPMD.CountInLoopExpression")
 * @SuppressWarnings("PHPMD.ExitExpression")
 * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
 */
final class ProcessPool
{
    /**
     * @param int  $workers         Число параллельных процессов
     * @param bool $forceSequential Принудительно без fork (тесты / окружения без pcntl)
     */
    public function __construct(
        private readonly int $workers = 2,
        private readonly bool $forceSequential = false,
    ) {
    }

    /**
     * Параллельно применяет callable к каждому элементу (генератор результатов).
     *
     * @template TItem
     * @template TResult
     *
     * @param list<TItem>             $items
     * @param callable(TItem):TResult $worker
     *
     * @return \Generator<int, TResult>
     */
    public function map(array $items, callable $worker): \Generator
    {
        if ($items === []) {
            return;
        }

        if ($this->forceSequential || !$this->supportsFork()) {
            foreach ($items as $item) {
                yield $worker($item);
            }

            return;
        }

        yield from $this->mapForked($items, $worker);
    }

    /**
     * Пишет length-prefixed serialize(result) в stream (для child и тестов).
     *
     * @param resource $stream
     */
    public function writeWorkerResult(mixed $stream, mixed $result): void
    {
        $payload = serialize($result);
        fwrite($stream, pack('N', strlen($payload)) . $payload);
    }

    /**
     * Читает length-prefixed payload от worker.
     *
     * @param resource $stream
     */
    public function readWorkerPayload(mixed $stream): string
    {
        $header = fread($stream, 4);
        $length = is_string($header) && strlen($header) === 4 ? unpack('Nlen', $header) : false;
        $body = '';

        if (is_array($length) && isset($length['len']) && is_int($length['len'])) {
            $need = $length['len'];

            while (strlen($body) < $need) {
                $remain = $need - strlen($body);

                if ($remain < 1) { // @codeCoverageIgnore
                    break; // @codeCoverageIgnore
                }

                $chunk = fread($stream, $remain);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $body .= $chunk;
            }
        }

        return $body;
    }

    /**
     * Доступен ли pcntl_fork + waitpid.
     */
    private function supportsFork(): bool
    {
        return function_exists('pcntl_fork') && function_exists('pcntl_waitpid');
    }

    /**
     * Параллельный map через pcntl_fork + stream_socket_pair.
     *
     * @template TItem
     * @template TResult
     *
     * @param list<TItem>             $items
     * @param callable(TItem):TResult $worker
     *
     * @return \Generator<int, TResult>
     */
    private function mapForked(array $items, callable $worker): \Generator
    {
        $concurrency = max(1, $this->workers);
        $queue = $items;
        /** @var array<int, true> $running */
        $running = [];
        /** @var array<int, resource> $sockets */
        $sockets = [];

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < $concurrency) {
                /** @var TItem $item */
                $item = array_shift($queue);
                $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

                if ($pair === false) { // @codeCoverageIgnoreStart
                    yield $worker($item);

                    continue; // @codeCoverageIgnoreEnd
                }

                [$parent, $child] = $pair;
                $pid = pcntl_fork();

                if ($pid === -1) { // @codeCoverageIgnoreStart
                    fclose($parent);
                    fclose($child);
                    yield $worker($item);

                    continue; // @codeCoverageIgnoreEnd
                }

                if ($pid === 0) { // @codeCoverageIgnoreStart
                    fclose($parent);
                    $this->writeWorkerResult($child, $worker($item));
                    fclose($child);
                    exit(0); // @codeCoverageIgnoreEnd
                }

                fclose($child);
                $running[$pid] = true;
                $sockets[$pid] = $parent;
            }

            yield from $this->reapFinished($running, $sockets);
        }
    }

    /**
     * Забирает один завершённый worker или ждёт.
     *
     * @param array<int, true>     $running
     * @param array<int, resource> $sockets
     *
     * @return \Generator<int, mixed>
     */
    private function reapFinished(array &$running, array &$sockets): \Generator
    {
        $status = 0;
        $done = pcntl_waitpid(-1, $status, WNOHANG);

        if ($done > 0 && isset($sockets[$done])) {
            $stream = $sockets[$done];
            $body = $this->readWorkerPayload($stream);
            fclose($stream);
            unset($running[$done], $sockets[$done]);

            if ($body !== '') {
                yield unserialize($body, ['allowed_classes' => true]);
            }

            return;
        }

        if ($done === 0 && $running !== []) {
            usleep(10_000);
        }
    }
}
