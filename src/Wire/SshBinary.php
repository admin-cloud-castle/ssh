<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Wire;

/**
 * Кодирование и декодирование бинарных типов SSH по RFC 4251 (uint32, string, mpint, name-list).
 */
final class SshBinary
{
    /**
     * Кодирует беззнаковое 32-битное целое в big-endian (4 байта).
     *
     * @param int $value Значение 0…2³²−1 (старшие биты обрезает pack)
     *
     * @return string Ровно 4 байта
     */
    public static function uint32(int $value): string
    {
        return pack('N', $value);
    }

    /**
     * Читает uint32 из буфера и сдвигает $offset на 4 (или до конца при нехватке данных).
     *
     * @param string $buffer Исходный бинарный буфер
     * @param int    $offset Текущая позиция чтения (обновляется)
     *
     * @return int Прочитанное значение или 0 при коротком буфере / сбое unpack
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
     * Кодирует SSH string: uint32(длина) || байты.
     *
     * @param string $value Произвольные байты (включая бинарные)
     *
     * @return string Длина + содержимое
     */
    public static function sshString(string $value): string
    {
        return self::uint32(strlen($value)) . $value;
    }

    /**
     * Читает SSH string; при выходе за границы буфера возвращает '' и сдвигает offset по uint32.
     *
     * @param string $buffer Исходный буфер
     * @param int    $offset Позиция чтения (обновляется)
     *
     * @return string Содержимое string или '' при ошибке длины
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
     * Кодирует name-list как SSH string с именами через запятую.
     *
     * @param list<string> $names Имена алгоритмов/сервисов без запятых внутри
     *
     * @return string SSH string вида «a,b,c»
     */
    public static function nameList(array $names): string
    {
        return self::sshString(implode(',', $names));
    }

    /**
     * Кодирует положительный mpint: при установленном старшем бите добавляет ведущий 0x00.
     *
     * @param string $bytes Беззнаковое big-endian представление числа
     *
     * @return string SSH string с mpint-байтами
     */
    public static function mpint(string $bytes): string
    {
        if ($bytes !== '' && (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return self::sshString($bytes);
    }

    /**
     * Читает mpint и снимает опциональный знаковый префикс 0x00.
     *
     * @param string $buffer Исходный буфер
     * @param int    $offset Позиция чтения (обновляется)
     *
     * @return string Байты величины без ведущего 0x00 (если он был)
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
