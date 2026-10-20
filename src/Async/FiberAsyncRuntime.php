<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Async;

use CloudCastle\Ssh\Contract\AsyncRuntimeInterface;
use CloudCastle\Ssh\Exception\TimeoutException;
use Fiber;

/**
 * Асинхронный рантайм на Fiber + stream_select без внешних зависимостей.
 *
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 * @SuppressWarnings("PHPMD.ShortVariable")
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
 * @SuppressWarnings("PHPMD.NPathComplexity")
 */
final class FiberAsyncRuntime implements AsyncRuntimeInterface
{
    private readonly StreamSelectorInterface $selector;

    /**
     * @param StreamSelectorInterface|null $selector Селектор I/O (по умолчанию нативный)
     */
    public function __construct(?StreamSelectorInterface $selector = null)
    {
        $this->selector = $selector ?? new NativeStreamSelector();
    }

    /**
     * {@inheritdoc}
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
     * {@inheritdoc}
     */
    #[\Override]
    public function awaitReadable(mixed $stream, float $timeoutSeconds): void
    {
        $this->suspend('read', $stream, $timeoutSeconds);
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function awaitWritable(mixed $stream, float $timeoutSeconds): void
    {
        $this->suspend('write', $stream, $timeoutSeconds);
    }

    /**
     * Проверяет payload ожидания I/O.
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
     * Наличие обязательных ключей payload.
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
     * Приостанавливает текущий Fiber до готовности I/O.
     *
     * @param mixed $stream Stream resource
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
     * Select по одному stream с дробным таймаутом.
     *
     * @param mixed $stream Stream resource
     *
     * @throws TimeoutException
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

        if ($result === false) {
            throw new TimeoutException(sprintf('Ошибка ожидания %s', $op));
        }

        if ($result === 0) {
            throw new TimeoutException(sprintf('Таймаут ожидания %s', $op));
        }
    }

    /**
     * MEMORY/TEMP не требуют select.
     *
     * @param resource $stream
     */
    private function isMemoryStream(mixed $stream): bool
    {
        $meta = stream_get_meta_data($stream);
        $streamType = $meta['stream_type'];

        return $streamType === 'MEMORY' || $streamType === 'TEMP';
    }

    /**
     * Корзины read/write для операции.
     *
     * @param resource $stream
     *
     * @return array{0: array<int, resource>|null, 1: array<int, resource>|null}
     *
     * @throws TimeoutException
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
     * Делит float-таймаут на секунды и микросекунды без округления вверх до 1с.
     *
     * @return array{0: int, 1: int}
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
