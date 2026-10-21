<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Support;

use CloudCastle\Ssh\Async\StreamSelectorInterface;

/**
 * Тестовый селектор: фиксирует аргументы select и возвращает заданный результат.
 */
final class RecordingStreamSelector implements StreamSelectorInterface
{
    /** Число вызовов select. @var int */
    public int $callCount = 0;

    /**
     * Последняя корзина read, переданная в select.
     *
     * @var array<int, resource>|null
     */
    public ?array $lastRead = null;

    /**
     * Последняя корзина write, переданная в select.
     *
     * @var array<int, resource>|null
     */
    public ?array $lastWrite = null;

    /** Последний таймаут select в секундах и микросекундах. */
    public int $lastSeconds = -1;

    public int $lastMicroseconds = -1;

    /**
     * @param int|false $returnValue Результат, который вернёт select
     */
    public function __construct(private readonly int|false $returnValue = 1)
    {
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    public function select(
        ?array &$read,
        ?array &$write,
        ?array &$except,
        int $seconds,
        int $microseconds = 0,
    ): int|false {
        ++$this->callCount;
        $this->lastRead = $read;
        $this->lastWrite = $write;
        $this->lastSeconds = $seconds;
        $this->lastMicroseconds = $microseconds;

        return $this->returnValue;
    }
}
