<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Support;

/**
 * Сужение mixed → non-empty-string для PHPStan и Psalm.
 */
final class NonEmptyString
{
    /**
     * Возвращает non-empty-string или null, если значение пустое/не строка.
     *
     * @return non-empty-string|null
     */
    public static function tryFrom(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
