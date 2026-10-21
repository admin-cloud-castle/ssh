<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Support;

/**
 * Stream wrapper: stream_set_timeout / STREAM_OPTION_READ_TIMEOUT всегда false.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 * @SuppressWarnings("PHPMD.CamelCaseMethodName")
 */
final class FalseTimeoutStreamWrapper
{
    /** @var resource|null */
    public $context;

    private int $offset = 0;

    private string $buffer = '';

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        unset($path, $mode, $options, $openedPath);
        $this->buffer = '';
        $this->offset = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->buffer, $this->offset, $count);
        $this->offset += strlen($chunk);

        return $chunk;
    }

    public function stream_write(string $data): int
    {
        $this->buffer .= $data;

        return strlen($data);
    }

    public function stream_tell(): int
    {
        return $this->offset;
    }

    public function stream_eof(): bool
    {
        return $this->offset >= strlen($this->buffer);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $length = strlen($this->buffer);

        if ($whence === SEEK_SET) {
            $this->offset = $offset;
        }

        if ($whence === SEEK_CUR) {
            $this->offset += $offset;
        }

        if ($whence === SEEK_END) {
            $this->offset = $length + $offset;
        }

        return $this->offset >= 0 && $this->offset <= $length;
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        unset($option, $arg1, $arg2);

        return false;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }
}
