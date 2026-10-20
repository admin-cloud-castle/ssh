<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Support;

/**
 * Безопасное удаление временных файлов тестов.
 */
final class TempPath
{
    /**
     * Удаляет файл только если он лежит внутри sys_get_temp_dir().
     */
    public static function unlinkIfTemp(string $path): void
    {
        $tempRoot = realpath(sys_get_temp_dir());

        if (!is_string($tempRoot)) {
            return;
        }

        $real = realpath($path);

        if (!is_string($real)) {
            return;
        }

        if (!str_starts_with($real, $tempRoot)) {
            return;
        }

        // Путь уже проверен realpath + префикс temp dir.
        unlink($real); // nosemgrep: php.lang.security.unlink-use.unlink-use
    }
}
