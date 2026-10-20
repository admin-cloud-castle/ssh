<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Contract;

/**
 * Прикладной SFTP-клиент.
 */
interface SftpClientInterface
{
    /**
     * Читает файл целиком.
     *
     * @throws \CloudCastle\Ssh\Exception\ConnectionException
     */
    public function get(string $remotePath): string;

    /**
     * Пишет содержимое в удалённый файл.
     *
     * @throws \CloudCastle\Ssh\Exception\ConnectionException
     */
    public function put(string $remotePath, string $contents): void;

    /**
     * Список имён в каталоге (генератор).
     *
     * @return \Generator<int, string>
     *
     * @throws \CloudCastle\Ssh\Exception\ConnectionException
     */
    public function list(string $remotePath): \Generator;

    /**
     * Создаёт каталог.
     *
     * @throws \CloudCastle\Ssh\Exception\ConnectionException
     */
    public function mkdir(string $remotePath): void;

    /**
     * Удаляет файл.
     *
     * @throws \CloudCastle\Ssh\Exception\ConnectionException
     */
    public function delete(string $remotePath): void;

    /**
     * Потоково читает файл чанками.
     *
     * @return \Generator<int, string>
     *
     * @throws \CloudCastle\Ssh\Exception\ConnectionException
     */
    public function streamRead(string $remotePath, int $chunkSize = 32768): \Generator;
}