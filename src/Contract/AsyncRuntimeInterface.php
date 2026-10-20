<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Contract;

/**
 * Кооперативный async-рантайм (Fiber + stream_select).
 */
interface AsyncRuntimeInterface
{
    /**
     * Запускает задачу в Fiber и крутит event-loop до завершения.
     *
     * @param callable(): mixed $task
     */
    public function run(callable $task): mixed;

    /**
     * Ждёт читаемости stream (из Fiber — suspend).
     *
     * @param mixed $stream
     *
     * @throws \CloudCastle\Ssh\Exception\TimeoutException
     */
    public function awaitReadable(mixed $stream, float $timeoutSeconds): void;

    /**
     * Ждёт записываемости stream.
     *
     * @param mixed $stream
     *
     * @throws \CloudCastle\Ssh\Exception\TimeoutException
     */
    public function awaitWritable(mixed $stream, float $timeoutSeconds): void;
}