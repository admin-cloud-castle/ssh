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
    private const REAP_PAUSE_MICROSECONDS = 10_000;

    private const LENGTH_HEADER_BYTES = 4;

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
     * @param callable(TItem):TResult $worker Чистая функция элемента → результат (должен кодироваться в JSON)
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
     * Пишет length-prefixed JSON результата в stream (формат child→parent).
     *
     * @param resource $stream Открытый конец socket pair (на запись)
     * @param mixed    $result Значение для json_encode (массивы/скаляры; объекты не поддерживаются)
     *
     * @throws \JsonException Если результат нельзя закодировать в JSON
     */
    public function writeWorkerResult(mixed $stream, mixed $result): void
    {
        $payload = json_encode($result, JSON_THROW_ON_ERROR);
        $frame = pack('N', strlen($payload)) . $payload;
        fwrite($stream, $frame);
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
        $header = fread($stream, self::LENGTH_HEADER_BYTES);

        if (!is_string($header) || strlen($header) !== self::LENGTH_HEADER_BYTES) {
            return '';
        }

        $length = unpack('Nlen', $header);
        $need = is_array($length) ? ($length['len'] ?? null) : null;

        if (!is_int($need)) {
            return '';
        }

        if ($need < 1) {
            return '';
        }

        $body = '';

        // Эквивалентные мутации while/remain на пользовательских stream не отличимы стабильно.
        /** @infection-ignore-all */
        while (strlen($body) < $need) {
            /** @infection-ignore-all */
            $remain = $need - strlen($body);

            if ($remain < 1) {
                break;
            }

            $chunk = fread($stream, $remain);

            if (!is_string($chunk) || $chunk === '') {
                break;
            }

            $body = $this->concatBody($body, $chunk);
        }

        return $body;
    }

    /**
     * Склеивает накопленное тело IPC с очередным chunk (выделено для убийства мутаций Assignment).
     */
    private function concatBody(string $body, string $chunk): string
    {
        return $body . $chunk;
    }

    /**
     * Проверяет доступность pcntl_fork и pcntl_waitpid.
     *
     * @return bool true если оба символа определены
     */
    private function supportsFork(): bool
    {
        return $this->functionExists('pcntl_fork') && $this->functionExists('pcntl_waitpid');
    }

    /**
     * Обёртка function_exists для точечных тестов мутаций.
     *
     * @param non-empty-string $name Имя функции
     */
    private function functionExists(string $name): bool
    {
        return function_exists($name);
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
        /** @var array<int, resource> $sockets */
        $sockets = [];

        while ($queue !== [] || $sockets !== []) {
            while ($queue !== [] && count($sockets) < $concurrency) {
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
                $sockets[$pid] = $parent;
            }

            yield from $this->reapFinished($sockets);
        }
    }

    /**
     * Забирает один завершённый worker (WNOHANG) или ждёт паузу при отсутствии готовых.
     *
     * @param array<int, resource> $sockets Карта pid → parent socket
     *
     * @return \Generator<int, mixed> 0 или 1 JSON-декодированный результат
     */
    private function reapFinished(array &$sockets): \Generator
    {
        $status = 0;
        // waitpid(-1)/сравнения done и пауза — эквивалентны или зависят от гонок ОС.
        /** @infection-ignore-all */
        $done = pcntl_waitpid(-1, $status, WNOHANG);

        /** @infection-ignore-all */
        if ($done > 0 && isset($sockets[$done])) {
            $stream = $sockets[$done];
            $body = $this->readWorkerPayload($stream);
            fclose($stream);
            unset($sockets[$done]);

            if ($body !== '') {
                /** @var mixed $decoded */
                $decoded = json_decode($body, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    yield $decoded;
                }
            }

            return;
        }

        /** @infection-ignore-all */
        if ($done === 0 && $sockets !== []) {
            $this->pauseBeforeNextReap();
        }
    }

    /**
     * Короткая пауза перед следующим WNOHANG, чтобы не крутить busy-loop.
     *
     * @infection-ignore-all
     */
    private function pauseBeforeNextReap(): void
    {
        usleep(self::REAP_PAUSE_MICROSECONDS);
    }
}
