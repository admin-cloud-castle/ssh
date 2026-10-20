<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Async;

/**
 * Нативный stream_select.
 *
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
final class NativeStreamSelector implements StreamSelectorInterface
{
    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function select(
        ?array &$read,
        ?array &$write,
        ?array &$except,
        int $seconds,
        int $microseconds = 0,
    ): int|false {
        return @stream_select($read, $write, $except, $seconds, $microseconds);
    }
}
