<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Async;

use CloudCastle\Ssh\Contract\AsyncRuntimeInterface;
use CloudCastle\Ssh\Exception\TimeoutException;
use Fiber;

/**
 * Кооперативный async-рантайм: Fiber + ожидание готовности stream через StreamSelectorInterface.
 *
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 * @SuppressWarnings("PHPMD.ShortVariable")
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
 * @SuppressWarnings("PHPMD.NPathComplexity")
 */
final class FiberAsyncRuntime implements AsyncRuntimeInterface
{
    /**
     * Селектор I/O (по умолчанию NativeStreamSelector).
     *
     * @var StreamSelectorInterface
     */
    private readonly StreamSelectorInterface $selector;

    /**
     * Создаёт рантайм с заданным или нативным селектором.
     *
     * @param StreamSelectorInterface|null $selector Селектор stream_select; null — NativeStreamSelector
     */
    public function __construct(?StreamSelectorInterface $selector = null)
    {
        $this->selector = $selector ?? new NativeStreamSelector();
    }

    /**
     * Запускает задачу в Fiber и обрабатывает suspend-ожидания I/O до завершения.
     *
     * @param callable(): mixed $task Замыкание без аргументов
     *
     * @return mixed Результат $task
     *
     * @throws TimeoutException При таймауте/ошибке ожидания внутри цикла
     * @throws \Throwable       Исключения из $task
     *
     * @psalm-suppress MixedAssignment
     */
    #[\Override]
    public function run(callable $task): mixed
    {
        $fiber = new Fiber($task);
        $value = $fiber->start();

        while (!$fiber->isTerminated()) {
            if (!$this->isWaitPayload($value)) {
                $value = $fiber->resume();
                continue;
            }

            $this->wait($value['stream'], $value['op'], (float) $value['timeout']);
            $value = $fiber->resume();
        }

        return $fiber->getReturn();
    }

    /**
     * Ждёт готовности stream к чтению (suspend из Fiber или блокирующий wait).
     *
     * @param mixed $stream         PHP stream resource
     * @param float $timeoutSeconds Таймаут в секундах
     *
     * @throws TimeoutException При таймауте или неверном stream
     */
    #[\Override]
    public function awaitReadable(mixed $stream, float $timeoutSeconds): void
    {
        $this->suspend('read', $stream, $timeoutSeconds);
    }

    /**
     * Ждёт готовности stream к записи (suspend из Fiber или блокирующий wait).
     *
     * @param mixed $stream         PHP stream resource
     * @param float $timeoutSeconds Таймаут в секундах
     *
     * @throws TimeoutException При таймауте или неверном stream
     */
    #[\Override]
    public function awaitWritable(mixed $stream, float $timeoutSeconds): void
    {
        $this->suspend('write', $stream, $timeoutSeconds);
    }

    /**
     * Проверяет, что значение — корректный payload ожидания I/O от Fiber::suspend.
     *
     * @param mixed $value Значение, полученное из Fiber::start/resume
     *
     * @return bool true если это массив с op/stream/timeout нужных типов
     *
     * @phpstan-assert-if-true array{op: string, stream: mixed, timeout: float|int} $value
     * @psalm-assert-if-true array{op: string, stream: mixed, timeout: float|int} $value
     */
    private function isWaitPayload(mixed $value): bool
    {
        if (!$this->hasWaitKeys($value)) {
            return false;
        }

        /** @var array{op: mixed, stream: mixed, timeout: mixed} $value */
        return is_string($value['op'])
            && (is_int($value['timeout']) || is_float($value['timeout']));
    }

    /**
     * Проверяет наличие ключей op, stream и timeout в массиве.
     *
     * @param mixed $value Кандидат в wait-payload
     *
     * @return bool true если $value — массив с тремя ключами
     *
     * @phpstan-assert-if-true array<mixed> $value
     */
    private function hasWaitKeys(mixed $value): bool
    {
        return is_array($value)
            && array_key_exists('op', $value)
            && array_key_exists('stream', $value)
            && array_key_exists('timeout', $value);
    }

    /**
     * Приостанавливает текущий Fiber или выполняет блокирующий wait вне Fiber.
     *
     * @param string $op             Операция: «read» или «write»
     * @param mixed  $stream         PHP stream resource
     * @param float  $timeoutSeconds Таймаут в секундах
     *
     * @throws TimeoutException При ошибке wait
     */
    private function suspend(string $op, mixed $stream, float $timeoutSeconds): void
    {
        if (!Fiber::getCurrent() instanceof Fiber) {
            $this->wait($stream, $op, $timeoutSeconds);

            return;
        }

        Fiber::suspend([
            'op' => $op,
            'stream' => $stream,
            'timeout' => $timeoutSeconds,
        ]);
    }

    /**
     * Выполняет select по одному stream с дробным таймаутом.
     *
     * Потоки MEMORY/TEMP считаются сразу готовыми (без select).
     *
     * @param mixed  $stream         PHP stream resource
     * @param string $op             «read» или «write»
     * @param float  $timeoutSeconds Таймаут в секундах
     *
     * @throws TimeoutException При не-resource, ошибке select, таймауте или неизвестной $op
     */
    private function wait(mixed $stream, string $op, float $timeoutSeconds): void
    {
        if (!is_resource($stream)) {
            throw new TimeoutException('Ожидался stream resource');
        }

        if ($this->isMemoryStream($stream)) {
            return;
        }

        [$read, $write] = $this->bucketsForOp($stream, $op);
        [$seconds, $microseconds] = $this->splitTimeout($timeoutSeconds);
        $except = null;
        $result = $this->selector->select($read, $write, $except, $seconds, $microseconds);

        if ($result === false) { // @codeCoverageIgnore
            throw new TimeoutException(sprintf('Ошибка ожидания %s', $op)); // @codeCoverageIgnore
        }

        if ($result === 0) {
            throw new TimeoutException(sprintf('Таймаут ожидания %s', $op));
        }
    }

    /**
     * Определяет, является ли stream in-memory (MEMORY/TEMP) и не требует select.
     *
     * @param resource $stream Открытый stream
     *
     * @return bool true для php://memory и php://temp
     */
    private function isMemoryStream(mixed $stream): bool
    {
        $meta = stream_get_meta_data($stream);
        $streamType = $meta['stream_type'];

        return $streamType === 'MEMORY' || $streamType === 'TEMP';
    }

    /**
     * Собирает корзины read/write для операции select.
     *
     * @param resource $stream Целевой stream
     * @param string   $op     «read» или «write»
     *
     * @return array{0: array<int, resource>|null, 1: array<int, resource>|null} [read, write]
     *
     * @throws TimeoutException Если $op неизвестна
     */
    private function bucketsForOp(mixed $stream, string $op): array
    {
        if ($op === 'read') {
            return [[$stream], null];
        }

        if ($op === 'write') {
            return [null, [$stream]];
        }

        throw new TimeoutException(sprintf('Неизвестная операция ожидания: %s', $op));
    }

    /**
     * Делит float-таймаут на целые секунды и микросекунды без ложного округления вверх до 1 с.
     *
     * @param float $timeoutSeconds Таймаут; ≤0 даёт [0, 0]
     *
     * @return array{0: int, 1: int} [seconds, microseconds]
     */
    private function splitTimeout(float $timeoutSeconds): array
    {
        if ($timeoutSeconds <= 0.0) {
            return [0, 0];
        }

        $seconds = (int) $timeoutSeconds;
        $fraction = $timeoutSeconds - (float) $seconds;
        $microseconds = (int) round($fraction * 1_000_000.0);

        if ($microseconds >= 1_000_000) {
            ++$seconds;
            $microseconds = 0;
        }

        return [$seconds, $microseconds];
    }
}
