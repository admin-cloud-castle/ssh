<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Async;

/**
 * Нативная реализация StreamSelectorInterface через PHP stream_select.
 *
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
final class NativeStreamSelector implements StreamSelectorInterface
{
    /**
     * Микросекунды таймаута stream_select, если аргумент не передан.
     *
     * @var int
     */
    public const DEFAULT_MICROSECONDS = 0;

    /**
     * Вызывает stream_select с подавлением предупреждений (ошибка → false).
     *
     * @param array<int, resource>|null $read         Корзина на чтение или null
     * @param array<int, resource>|null $write        Корзина на запись или null
     * @param array<int, resource>|null $except       Корзина exceptional или null
     * @param int                       $seconds      Целые секунды таймаута
     * @param int                       $microseconds Микросекунды таймаута
     *
     * @param-out array<array-key, resource>|null $read
     * @param-out array<array-key, resource>|null $write
     * @param-out array<array-key, resource>|null $except
     *
     * @return int|false Как у stream_select
     */
    #[\Override]
    public function select(
        ?array &$read,
        ?array &$write,
        ?array &$except,
        int $seconds,
        int $microseconds = self::DEFAULT_MICROSECONDS,
    ): int|false {
        return @stream_select($read, $write, $except, $seconds, $microseconds);
    }
}
