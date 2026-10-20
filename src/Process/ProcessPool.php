<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Process;

/**
 * Пул worker-процессов (pcntl_fork) для CPU-bound задач поверх SSH-операций.
 *
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
final class ProcessPool
{
    /**
     * @param int $workers Число параллельных процессов
     */
    public function __construct(private readonly int $workers = 2)
    {
    }

    /**
     * Параллельно применяет callable к каждому элементу (генератор результатов).
     *
     * @param list<mixed>           $items
     * @param callable(mixed):mixed $worker
     *
     * @return \Generator<int, mixed>
     */
    public function map(array $items, callable $worker): \Generator
    {
        if ($items === []) {
            return;
        }

        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            foreach ($items as $item) {
                yield $worker($item);
            }

            return;
        }

        $concurrency = max(1, $this->workers);
        $queue = array_values($items);
        $running = [];
        $sockets = [];

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < $concurrency) {
                $item = array_shift($queue);
                $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

                if ($pair === false) {
                    yield $worker($item);
                    continue;
                }

                [$parent, $child] = $pair;
                $pid = pcntl_fork();

                if ($pid === -1) {
                    fclose($parent);
                    fclose($child);
                    yield $worker($item);
                    continue;
                }

                if ($pid === 0) {
                    fclose($parent);
                    $result = $worker($item);
                    $payload = serialize($result);
                    fwrite($child, pack('N', strlen($payload)) . $payload);
                    fclose($child);
                    exit(0);
                }

                fclose($child);
                $running[$pid] = true;
                $sockets[$pid] = $parent;
            }

            $status = 0;
            $done = pcntl_waitpid(-1, $status, WNOHANG);

            if ($done > 0 && isset($sockets[$done])) {
                $stream = $sockets[$done];
                $header = fread($stream, 4);
                $length = is_string($header) && strlen($header) === 4 ? unpack('Nlen', $header) : false;
                $body = '';

                if (is_array($length) && isset($length['len']) && is_int($length['len'])) {
                    $need = $length['len'];

                    while (strlen($body) < $need) {
                        $chunk = fread($stream, $need - strlen($body));

                        if ($chunk === false || $chunk === '') {
                            break;
                        }

                        $body .= $chunk;
                    }
                }

                fclose($stream);
                unset($running[$done], $sockets[$done]);

                if ($body !== '') {
                    yield unserialize($body, ['allowed_classes' => true]);
                }
            } elseif ($done === 0 && $running !== []) {
                usleep(10_000);
            }
        }
    }
}
