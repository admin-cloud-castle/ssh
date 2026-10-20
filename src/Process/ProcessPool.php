<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Process;

/**
 * Пул worker-процессов (pcntl_fork + stream_socket_pair) для параллельных CPU-bound задач.
 *
 * При отсутствии pcntl или `$forceSequential=true` выполняет map последовательно в текущем процессе.
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
     * Создаёт пул с заданной параллельностью.
     *
     * @param int  $workers         Число параллельных worker-процессов (≥1 после max в mapForked)
     * @param bool $forceSequential true — всегда последовательный режим (тесты / без pcntl)
     */
    public function __construct(
        /** Число параллельных worker-процессов. @var int */
        private readonly int $workers = 2,
        /** Принудительный последовательный режим без fork. @var bool */
        private readonly bool $forceSequential = false,
    ) {
    }

    /**
     * Применяет $worker к каждому элементу; результаты отдаёт генератором.
     *
     * Порядок результатов при fork не гарантирован; при sequential — порядок $items.
     *
     * @template TItem
     * @template TResult
     *
     * @param list<TItem>             $items  Входные элементы; [] — пустой генератор
     * @param callable(TItem):TResult $worker Чистая функция элемента → результат (должен сериализоваться)
     *
     * @return \Generator<int, TResult> Поток результатов
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
     * Пишет length-prefixed serialize($result) в stream (формат child→parent).
     *
     * @param resource $stream Открытый конец socket pair (на запись)
     * @param mixed    $result Значение для serialize (только массивы/скаляры — allowed_classes=false)
     */
    public function writeWorkerResult(mixed $stream, mixed $result): void
    {
        $payload = serialize($result);
        fwrite($stream, pack('N', strlen($payload)) . $payload);
    }

    /**
     * Читает length-prefixed payload от worker; при обрыве возвращает частичное/пустое тело.
     *
     * @param resource $stream Открытый конец socket pair (на чтение)
     *
     * @return string Сырые байты тела без length-префикса; '' при коротком заголовке
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
     * Проверяет доступность pcntl_fork и pcntl_waitpid.
     *
     * @return bool true если оба символа определены
     */
    private function supportsFork(): bool
    {
        return function_exists('pcntl_fork') && function_exists('pcntl_waitpid');
    }

    /**
     * Параллельный map через fork; при сбое pair/fork падает в inline yield.
     *
     * @template TItem
     * @template TResult
     *
     * @param list<TItem>             $items  Непустой список элементов
     * @param callable(TItem):TResult $worker Worker-функция
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
     * Забирает один завершённый worker (WNOHANG) или ждёт usleep при отсутствии готовых.
     *
     * @param array<int, true>     $running Карта pid → true активных детей
     * @param array<int, resource> $sockets Карта pid → parent socket
     *
     * @return \Generator<int, mixed> 0 или 1 unserialize-результат
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
                // IPC только от своих fork-worker'ов; классы запрещены (массивы/скаляры).
                yield unserialize($body, ['allowed_classes' => false]);
            }

            return;
        }

        if ($done === 0 && $running !== []) {
            usleep(10_000);
        }
    }
}
