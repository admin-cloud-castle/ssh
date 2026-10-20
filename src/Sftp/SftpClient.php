<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Sftp;

use CloudCastle\Ssh\Contract\SftpClientInterface;
use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Wire\SshBinary;

/**
 * Прикладной SFTP-клиент поверх нативной SSH-сессии (без Composer runtime-зависимостей).
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.ShortVariable")
 * @SuppressWarnings("PHPMD.StaticAccess")
 */
final class SftpClient implements SftpClientInterface
{
    /**
     * Счётчик request-id для SSH_FXP_* пакетов (нарастающий с 1).
     */
    private int $requestId = 0;

    /**
     * Создаёт клиент над уже готовым SFTP-каналом.
     *
     * @param SftpChannelInterface $session Канал sendSftp/receiveSftp
     */
    public function __construct(private readonly SftpChannelInterface $session)
    {
    }

    /**
     * Читает удалённый файл целиком через OPEN + READ до EOF.
     *
     * @param string $remotePath Абсолютный или относительный путь на сервере
     *
     * @return string Содержимое файла (может быть пустым)
     *
     * @throws ConnectionException При ошибке OPEN/READ/CLOSE
     */
    #[\Override]
    public function get(string $remotePath): string
    {
        $content = '';

        foreach ($this->streamRead($remotePath) as $chunk) {
            $content .= $chunk;
        }

        return $content;
    }

    /**
     * Потоково читает файл чанками (экономия памяти).
     *
     * @return \Generator<int, string>
     *
     * @throws ConnectionException
     */
    #[\Override]
    public function streamRead(string $remotePath, int $chunkSize = 32768): \Generator
    {
        $handle = $this->open($remotePath, 0x00000001);
        $offset = 0;
        $size = max(1, $chunkSize);

        try {
            while (true) {
                $chunk = $this->readHandle($handle, $offset, $size);

                if ($chunk === '') {
                    break;
                }

                $offset += strlen($chunk);

                yield $chunk;
            }
        } finally {
            $this->closeHandle($handle);
        }
    }

    /**
     * Записывает (создаёт/перезаписывает) удалённый файл через OPEN + WRITE.
     *
     * @param string $remotePath Абсолютный или относительный путь на сервере
     * @param string $contents   Байты для записи (в т.ч. бинарные)
     *
     * @throws ConnectionException При ошибке OPEN/WRITE/CLOSE
     */
    #[\Override]
    public function put(string $remotePath, string $contents): void
    {
        $handle = $this->open($remotePath, 0x0000001A);
        $offset = 0;
        $length = strlen($contents);

        while ($offset < $length) {
            $chunk = substr($contents, $offset, 32_768);
            $this->writeHandle($handle, $offset, $chunk);
            $offset += strlen($chunk);
        }

        $this->closeHandle($handle);
    }

    /**
     * Возвращает имена записей каталога (без «.» / «..» фильтрации — как отдал сервер).
     *
     * @param string $remotePath Путь к каталогу; по умолчанию текущий «.»
     *
     * @return \Generator<int, string> Имена файлов и подкаталогов
     *
     * @throws ConnectionException При ошибке OPENDIR/READDIR/CLOSE
     */
    #[\Override]
    public function list(string $remotePath = '.'): \Generator
    {
        $handle = $this->openDir($remotePath);

        try {
            while (true) {
                $batch = $this->readDir($handle);

                if ($batch === []) {
                    break;
                }

                foreach ($batch as $name) {
                    yield $name;
                }
            }
        } finally {
            $this->closeHandle($handle);
        }
    }

    /**
     * Создаёт каталог с правами 0777 (маска сервера может сузить).
     *
     * @param string $remotePath Путь нового каталога
     *
     * @throws ConnectionException При ненулевом SSH_FXP_STATUS
     */
    #[\Override]
    public function mkdir(string $remotePath): void
    {
        $id = ++$this->requestId;
        $this->session->sendSftp(
            chr(14) . SshBinary::uint32($id) . SshBinary::sshString($remotePath) . SshBinary::uint32(0x000001FF),
        );
        $this->expectStatus($id);
    }

    /**
     * Удаляет файл (не каталог) по пути.
     *
     * @param string $remotePath Путь к файлу
     *
     * @throws ConnectionException При ненулевом SSH_FXP_STATUS
     */
    #[\Override]
    public function delete(string $remotePath): void
    {
        $id = ++$this->requestId;
        $this->session->sendSftp(chr(13) . SshBinary::uint32($id) . SshBinary::sshString($remotePath));
        $this->expectStatus($id);
    }

    /**
     * Проверяет существование пути через SSH_FXP_STAT.
     *
     * @param string $remotePath Путь к файлу или каталогу
     *
     * @return bool true при SSH_FXP_ATTRS; false при ошибке/отсутствии
     */
    public function exists(string $remotePath): bool
    {
        try {
            $id = ++$this->requestId;
            $this->session->sendSftp(chr(17) . SshBinary::uint32($id) . SshBinary::sshString($remotePath));
            $packet = $this->session->receiveSftp();

            return $packet !== '' && ord($packet[0]) === 105;
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * Возвращает низкоуровневый SFTP-канал для расширенных сценариев.
     *
     * @return SftpChannelInterface Тот же канал, что передан в конструктор
     */
    public function resource(): SftpChannelInterface
    {
        return $this->session;
    }

    /**
     * Открывает файл и возвращает opaque handle.
     *
     * @param string $path  Путь на сервере
     * @param int    $flags Битовая маска SSH_FXF_* (например READ=1, WRITE|CREAT|TRUNC=0x1A)
     *
     * @return string Opaque handle для последующих READ/WRITE/CLOSE
     *
     * @throws ConnectionException Если ответ не SSH_FXP_HANDLE
     */
    private function open(string $path, int $flags): string
    {
        $id = ++$this->requestId;
        $this->session->sendSftp(
            chr(3)
            . SshBinary::uint32($id)
            . SshBinary::sshString($path)
            . SshBinary::uint32($flags)
            . SshBinary::uint32(0),
        );
        $packet = $this->session->receiveSftp();

        if ($packet === '' || ord($packet[0]) !== 102) {
            throw new ConnectionException('SFTP OPEN не удался: ' . $path);
        }

        $offset = 1;
        SshBinary::readUint32($packet, $offset);

        return SshBinary::readString($packet, $offset);
    }

    /**
     * Открывает каталог и возвращает opaque handle.
     *
     * @param string $path Путь к каталогу
     *
     * @return string Opaque handle для READDIR/CLOSE
     *
     * @throws ConnectionException Если ответ не SSH_FXP_HANDLE
     */
    private function openDir(string $path): string
    {
        $id = ++$this->requestId;
        $this->session->sendSftp(chr(11) . SshBinary::uint32($id) . SshBinary::sshString($path));
        $packet = $this->session->receiveSftp();

        if ($packet === '' || ord($packet[0]) !== 102) {
            throw new ConnectionException('SFTP OPENDIR не удался: ' . $path);
        }

        $offset = 1;
        SshBinary::readUint32($packet, $offset);

        return SshBinary::readString($packet, $offset);
    }

    /**
     * Читает порцию файла по handle.
     *
     * @param string $handle Opaque handle из open()
     * @param int    $offset Смещение в файле (байты от начала)
     * @param int    $length Сколько байт запросить (обычно ≤ 32768)
     *
     * @return string Прочитанные байты или '' при EOF/STATUS
     *
     * @throws ConnectionException При неожиданном типе ответа
     */
    private function readHandle(string $handle, int $offset, int $length): string
    {
        $id = ++$this->requestId;
        $this->session->sendSftp(
            chr(5)
            . SshBinary::uint32($id)
            . SshBinary::sshString($handle)
            . $this->uint64($offset)
            . SshBinary::uint32($length),
        );
        $packet = $this->session->receiveSftp();

        if ($packet === '') {
            return '';
        }

        $type = ord($packet[0]);

        if ($type === 101) {
            return '';
        }

        if ($type !== 103) {
            throw new ConnectionException('SFTP READ не удался');
        }

        $cursor = 1;
        SshBinary::readUint32($packet, $cursor);

        return SshBinary::readString($packet, $cursor);
    }

    /**
     * Записывает порцию данных по handle.
     *
     * @param string $handle Opaque handle из open()
     * @param int    $offset Смещение записи в файле
     * @param string $data   Байты для записи
     *
     * @throws ConnectionException При ненулевом SSH_FXP_STATUS
     */
    private function writeHandle(string $handle, int $offset, string $data): void
    {
        $id = ++$this->requestId;
        $this->session->sendSftp(
            chr(6)
            . SshBinary::uint32($id)
            . SshBinary::sshString($handle)
            . $this->uint64($offset)
            . SshBinary::sshString($data),
        );
        $this->expectStatus($id);
    }

    /**
     * Читает одну порцию имён из открытого каталога.
     *
     * @param string $handle Opaque handle из openDir()
     *
     * @return list<string> Имена из SSH_FXP_NAME или [] при EOF/STATUS
     *
     * @throws ConnectionException При неожиданном типе ответа
     */
    private function readDir(string $handle): array
    {
        $id = ++$this->requestId;
        $this->session->sendSftp(chr(12) . SshBinary::uint32($id) . SshBinary::sshString($handle));
        $packet = $this->session->receiveSftp();

        if ($packet === '') {
            return [];
        }

        $type = ord($packet[0]);

        if ($type === 101) {
            return [];
        }

        if ($type !== 104) {
            throw new ConnectionException('SFTP READDIR не удался');
        }

        $cursor = 1;
        SshBinary::readUint32($packet, $cursor);
        $count = SshBinary::readUint32($packet, $cursor);
        $names = [];

        for ($i = 0; $i < $count; ++$i) {
            $names[] = SshBinary::readString($packet, $cursor);
            SshBinary::readString($packet, $cursor);
            SshBinary::readUint32($packet, $cursor);
            $cursor += 4 + 8 + 8 + 4 + 4 + 4 + 4 + 4;
        }

        return $names;
    }

    /**
     * Закрывает file/dir handle.
     *
     * @param string $handle Opaque handle
     *
     * @throws ConnectionException При ненулевом SSH_FXP_STATUS
     */
    private function closeHandle(string $handle): void
    {
        $id = ++$this->requestId;
        $this->session->sendSftp(chr(4) . SshBinary::uint32($id) . SshBinary::sshString($handle));
        $this->expectStatus($id);
    }

    /**
     * Ожидает SSH_FXP_STATUS с code=0 для указанного request-id.
     *
     * @param int $requestId Идентификатор запроса, на который ждём ответ
     *
     * @throws ConnectionException При другом типе, id или ненулевом code
     */
    private function expectStatus(int $requestId): void
    {
        $packet = $this->session->receiveSftp();

        if ($packet === '' || ord($packet[0]) !== 101) {
            throw new ConnectionException('Ожидался SSH_FXP_STATUS');
        }

        $offset = 1;
        $id = SshBinary::readUint32($packet, $offset);
        $code = SshBinary::readUint32($packet, $offset);

        if ($id !== $requestId || $code !== 0) {
            throw new ConnectionException('SFTP status error code=' . $code);
        }
    }

    /**
     * Кодирует uint64 big-endian (старшие 32 бита + младшие 32 бита).
     *
     * @param int $value Неотрицательное смещение/длина в байтах
     *
     * @return string Ровно 8 байт
     */
    private function uint64(int $value): string
    {
        $hi = intdiv($value, 4_294_967_296);
        $lo = $value % 4_294_967_296;

        return SshBinary::uint32($hi) . SshBinary::uint32($lo);
    }
}
