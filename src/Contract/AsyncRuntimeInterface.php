<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Contract;

/**
 * Кооперативный async-рантайм на Fiber и ожидании готовности stream (stream_select).
 */
interface AsyncRuntimeInterface
{
    /**
     * Запускает задачу в Fiber и крутит event-loop до её завершения.
     *
     * Задача может вызывать awaitReadable/awaitWritable; вне Fiber ожидание выполняется напрямую.
     *
     * @param callable(): mixed $task Замыкание без аргументов; возвращаемое значение — результат run()
     *
     * @return mixed Значение, возвращённое $task (включая null)
     *
     * @throws \RuntimeException При таймауте или ошибке ожидания I/O внутри Fiber
     * @throws \Throwable        Любое исключение, выброшенное из $task
     */
    public function run(callable $task): mixed;

    /**
     * Ждёт готовности stream к чтению.
     *
     * Изнутри Fiber — suspend с последующим select в event-loop; вне Fiber — блокирующий wait.
     *
     * @param mixed $stream         PHP stream resource
     * @param float $timeoutSeconds Таймаут ожидания в секундах; ≤0 — немедленная проверка
     *
     * @throws \RuntimeException При таймауте, неверном stream или ошибке select
     */
    public function awaitReadable(mixed $stream, float $timeoutSeconds): void;

    /**
     * Ждёт готовности stream к записи.
     *
     * @param mixed $stream         PHP stream resource
     * @param float $timeoutSeconds Таймаут ожидания в секундах; ≤0 — немедленная проверка
     *
     * @throws \RuntimeException При таймауте, неверном stream или ошибке select
     */
    public function awaitWritable(mixed $stream, float $timeoutSeconds): void;
}
