<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Async;

/**
 * Абстракция stream_select для тестируемости и MSI.
 */
interface StreamSelectorInterface
{
    /**
     * Обёртка над stream_select с секундами и микросекундами.
     *
     * @param array<int, resource>|null $read
     * @param array<int, resource>|null $write
     * @param array<int, resource>|null $except
     */
    public function select(
        ?array &$read,
        ?array &$write,
        ?array &$except,
        int $seconds,
        int $microseconds = 0,
    ): int|false;
}
