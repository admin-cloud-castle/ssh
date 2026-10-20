<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Wire;

/**
 * Кодирование/декодирование бинарных типов SSH (RFC 4251).
 */
final class SshBinary
{
    /**
     * Беззнаковое uint32 big-endian.
     */
    public static function uint32(int $value): string
    {
        return pack('N', $value);
    }

    /**
     * Читает uint32 и сдвигает offset.
     *
     * @param-out int $offset
     */
    public static function readUint32(string $buffer, int &$offset): int
    {
        if ($offset + 4 > strlen($buffer)) {
            $offset = strlen($buffer);

            return 0;
        }

        $chunk = substr($buffer, $offset, 4);
        $offset += 4;
        $parts = unpack('Nval', $chunk);

        if (!is_array($parts)) { // @codeCoverageIgnore
            return 0; // @codeCoverageIgnore
        }

        return isset($parts['val']) && is_int($parts['val']) ? $parts['val'] : 0;
    }

    /**
     * SSH string: длина + байты.
     */
    public static function sshString(string $value): string
    {
        return self::uint32(strlen($value)) . $value;
    }

    /**
     * Читает SSH string.
     *
     * @param-out int $offset
     */
    public static function readString(string $buffer, int &$offset): string
    {
        $length = self::readUint32($buffer, $offset);

        if ($length < 0 || $offset + $length > strlen($buffer)) {
            return '';
        }

        $value = substr($buffer, $offset, $length);
        $offset += $length;

        return $value;
    }

    /**
     * name-list как SSH string через запятую.
     *
     * @param list<string> $names
     */
    public static function nameList(array $names): string
    {
        return self::sshString(implode(',', $names));
    }

    /**
     * Положительный mpint.
     */
    public static function mpint(string $bytes): string
    {
        if ($bytes !== '' && (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return self::sshString($bytes);
    }

    /**
     * Читает mpint без знакового префикса.
     *
     * @param-out int $offset
     */
    public static function readMpint(string $buffer, int &$offset): string
    {
        $raw = self::readString($buffer, $offset);

        if ($raw !== '' && $raw[0] === "\x00") {
            return substr($raw, 1);
        }

        return $raw;
    }
}
