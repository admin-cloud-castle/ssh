<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Async;

/**
 * Абстракция stream_select для подмены в тестах и изоляции MSI-мутаций.
 */
interface StreamSelectorInterface
{
    /**
     * Ожидает готовности наборов stream (обёртка над stream_select).
     *
     * Массивы передаются по ссылке и могут быть изменены реализацией (как у native select).
     *
     * @param array<int, resource>|null $read         Корзина на чтение или null
     * @param array<int, resource>|null $write        Корзина на запись или null
     * @param array<int, resource>|null $except       Корзина exceptional или null
     * @param int                       $seconds      Целые секунды таймаута (≥0)
     * @param int                       $microseconds Дробная часть таймаута в микросекундах (0…999999)
     *
     * @param-out array<array-key, resource>|null $read
     * @param-out array<array-key, resource>|null $write
     * @param-out array<array-key, resource>|null $except
     *
     * @return int|false Число готовых дескрипторов, 0 при таймауте, false при ошибке
     */
    public function select(
        ?array &$read,
        ?array &$write,
        ?array &$except,
        int $seconds,
        int $microseconds = 0,
    ): int|false;
}
