<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Session;

use CloudCastle\Ssh\Auth\PublicKeySigner;
use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Contract\SessionInterface;
use CloudCastle\Ssh\Contract\SftpClientInterface;
use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Sftp\SftpChannelInterface;
use CloudCastle\Ssh\Sftp\SftpClient;
use CloudCastle\Ssh\Wire\SshBinary;
use CloudCastle\Ssh\Wire\SshWire;
use GMP;


/**
 * Нативная SSH-2 сессия на PHP openssl/gmp без Composer runtime-зависимостей.
 *
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
 * @SuppressWarnings("PHPMD.NPathComplexity")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ShortVariable")
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
 * @SuppressWarnings("PHPMD.EmptyCatchBlock")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 */
final class NativeSession implements SessionInterface, SftpChannelInterface
{
    /**
     * SSH_MSG_DISCONNECT.
     *
     * @var int
     */
    private const MSG_DISCONNECT = 1;

    /**
     * SSH_MSG_SERVICE_REQUEST.
     *
     * @var int
     */
    private const MSG_SERVICE_REQUEST = 5;

    /**
     * SSH_MSG_SERVICE_ACCEPT.
     *
     * @var int
     */
    private const MSG_SERVICE_ACCEPT = 6;

    /**
     * SSH_MSG_KEXINIT.
     *
     * @var int
     */
    private const MSG_KEXINIT = 20;

    /**
     * SSH_MSG_NEWKEYS.
     *
     * @var int
     */
    private const MSG_NEWKEYS = 21;

    /**
     * SSH_MSG_KEXDH_INIT.
     *
     * @var int
     */
    private const MSG_KEXDH_INIT = 30;

    /**
     * SSH_MSG_KEXDH_REPLY.
     *
     * @var int
     */
    private const MSG_KEXDH_REPLY = 31;

    /**
     * SSH_MSG_USERAUTH_REQUEST.
     *
     * @var int
     */
    private const MSG_USERAUTH_REQUEST = 50;

    /**
     * SSH_MSG_USERAUTH_FAILURE.
     *
     * @var int
     */
    private const MSG_USERAUTH_FAILURE = 51;

    /**
     * SSH_MSG_USERAUTH_SUCCESS.
     *
     * @var int
     */
    private const MSG_USERAUTH_SUCCESS = 52;

    /**
     * SSH_MSG_CHANNEL_OPEN.
     *
     * @var int
     */
    private const MSG_CHANNEL_OPEN = 90;

    /**
     * SSH_MSG_CHANNEL_OPEN_CONFIRMATION.
     *
     * @var int
     */
    private const MSG_CHANNEL_OPEN_CONFIRMATION = 91;

    /**
     * SSH_MSG_CHANNEL_DATA.
     *
     * @var int
     */
    private const MSG_CHANNEL_DATA = 94;

    /**
     * SSH_MSG_CHANNEL_EOF.
     *
     * @var int
     */
    private const MSG_CHANNEL_EOF = 96;

    /**
     * SSH_MSG_CHANNEL_CLOSE.
     *
     * @var int
     */
    private const MSG_CHANNEL_CLOSE = 97;

    /**
     * SSH_MSG_CHANNEL_REQUEST.
     *
     * @var int
     */
    private const MSG_CHANNEL_REQUEST = 98;

    /**
     * SSH_MSG_CHANNEL_SUCCESS.
     *
     * @var int
     */
    private const MSG_CHANNEL_SUCCESS = 99;

    /**
     * Модуль diffie-hellman-group14-sha256 (RFC 3526) в hex.
     *
     * @var string
     */
    private const GROUP14_P =
        'FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD129024E088A67CC74'
        . '020BBEA63B139B22514A08798E3404DDEF9519B3CD3A431B302B0A6DF25F1437'
        . '4FE1356D6D51C245E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7ED'
        . 'EE386BFB5A899FA5AE9F24117C4B1FE649286651ECE45B3DC2007CB8A163BF05'
        . '98DA48361C55D39A69163FA8FD24CF5F83655D23DCA3AD961C62F356208552BB'
        . '9ED529077096966D670C354E4ABC9804F1746C08CA18217C32905E462E36CE3B'
        . 'E39E772C180E86039B2783A2EC07A28FB5C55DF06F4C52C9DE2BCBF695581718'
        . '3995497CEA956AE515D2261898FA051015728E5A8AACAA68FFFFFFFFFFFFFFFF';

    /**
     * Identification string клиента (RFC 4253).
     *
     * @var string
     */
    private const CLIENT_ID = 'SSH-2.0-CloudCastle_ConnectManager_1.0';

    /**
     * Транспорт пакетов.
     */
    private readonly SshWire $wire;

    /**
     * Identification string сервера без CR/LF.
     */
    private string $serverId = '';

    /**
     * Exchange hash H — session id для USERAUTH.
     */
    private string $sessionId = '';

    /**
     * true после успешной аутентификации до disconnect().
     */
    private bool $connected = false;

    /**
     * true после успешного INIT/VERSION SFTP subsystem.
     */
    private bool $sftpReady = false;

    /**
     * Локальный channel id (сейчас всегда 0).
     */
    private int $localChannel = 0;

    /**
     * Remote channel id из OPEN_CONFIRMATION; 0 — канал не открыт.
     */
    private int $remoteChannel = 0;

    /**
     * Накопленные CHANNEL_DATA до чтения приложением.
     */
    private string $channelBuffer = '';

    /**
     * Получен CHANNEL_EOF или CHANNEL_CLOSE.
     */
    private bool $channelEof = false;

    /** @var string */
    private string $hostKeyBlob = '';

    /** @var string */
    private string $remoteEndpoint = '';

    /**
     * Выполняет identification, KEX, userauth и опционально SFTP INIT.
     *
     * @param mixed            $stream     PHP stream resource после TCP connect
     * @param AuthCredentials  $auth       Password или publickey
     * @param bool             $preferSftp true — сразу поднять SFTP subsystem
     *
     * @throws ConnectionException При неверном stream, отсутствии gmp или ошибке протокола
     */
    public function __construct(mixed $stream, AuthCredentials $auth, bool $preferSftp = false)
    {
        if (!is_resource($stream)) {
            throw new ConnectionException('Ожидался stream resource для SSH');
        }

        if (!function_exists('gmp_init')) {
            throw new ConnectionException('Для нативного SSH требуется расширение gmp');
        }

        $this->wire = new SshWire($stream);
        $this->exchangeIdentification($stream);
        $this->performKeyExchange();
        $this->requestService('ssh-userauth');
        $this->authenticate($auth);
        $this->connected = true;

        if ($preferSftp) {
            $this->prepareSftp();
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return bool true после успешного конструктора до disconnect()
     */
    #[\Override]
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * {@inheritdoc}
     *
     * Отправляет DISCONNECT (reason 11) и закрывает wire.
     */
    #[\Override]
    public function disconnect(): void
    {
        if (!$this->connected) {
            return;
        }

        try {
            $this->wire->send(
                chr(self::MSG_DISCONNECT)
                . SshBinary::uint32(11)
                . SshBinary::sshString('bye')
                . SshBinary::sshString(''),
            );
        } catch (ConnectionException $disconnectError) {
            unset($disconnectError);
        }

        $this->wire->close();
        $this->connected = false;
    }

    /**
     * {@inheritdoc}
     *
     * @param string $command Команда для exec
     *
     * @return string Вывод канала до EOF
     *
     * @throws ConnectionException При закрытой сессии или ошибке протокола
     */
    #[\Override]
    public function exec(string $command): string
    {
        $this->assertConnected();
        $this->openSessionChannel();
        $this->wire->send(
            chr(self::MSG_CHANNEL_REQUEST)
            . SshBinary::uint32($this->remoteChannel)
            . SshBinary::sshString('exec')
            . "\x01"
            . SshBinary::sshString($command),
        );
        $this->expectType(self::MSG_CHANNEL_SUCCESS);

        return $this->drainChannel();
    }

    /**
     * {@inheritdoc}
     *
     * @param string $payload Байты CHANNEL_DATA
     *
     * @throws ConnectionException При закрытой сессии или ошибке записи
     */
    #[\Override]
    public function write(string $payload): void
    {
        $this->assertConnected();

        if ($this->remoteChannel === 0) {
            $this->openSessionChannel();
        }

        $this->wire->send(
            chr(self::MSG_CHANNEL_DATA)
            . SshBinary::uint32($this->remoteChannel)
            . SshBinary::sshString($payload),
        );
    }

    /**
     * {@inheritdoc}
     *
     * @param int $maxLength Максимум байт; ≤0 трактуется как 1
     *
     * @return string Фрагмент буфера или ''
     *
     * @throws ConnectionException При закрытой сессии
     */
    #[\Override]
    public function read(int $maxLength = 8192): string
    {
        $this->assertConnected();
        $this->pump(false);

        if ($this->channelBuffer === '') {
            return '';
        }

        $length = max(1, $maxLength);
        $chunk = substr($this->channelBuffer, 0, $length);
        $this->channelBuffer = substr($this->channelBuffer, strlen($chunk));

        return $chunk;
    }

    /**
     * {@inheritdoc}
     *
     * @return SftpClient Клиент поверх этой сессии
     *
     * @throws ConnectionException Если SFTP не поднялся
     */
    #[\Override]
    public function openSftp(): SftpClientInterface
    {
        $this->assertConnected();
        $this->prepareSftp();

        return new SftpClient($this);
    }

    /**
     * {@inheritdoc}
     *
     * @param string $sftpPayload Тело SFTP без внешнего length
     *
     * @throws ConnectionException При ошибке записи
     */
    #[\Override]
    public function sendSftp(string $sftpPayload): void
    {
        $this->write(SshBinary::uint32(strlen($sftpPayload)) . $sftpPayload);
    }

    /**
     * {@inheritdoc}
     *
     * @return string Один SFTP payload без внешнего uint32 length
     *
     * @throws ConnectionException При ошибке чтения framing
     */
    #[\Override]
    public function receiveSftp(): string
    {
        while (strlen($this->channelBuffer) < 4) {
            $this->pump(true);
        }

        $offset = 0;
        $length = SshBinary::readUint32($this->channelBuffer, $offset);

        while (strlen($this->channelBuffer) < 4 + $length) {
            $this->pump(true);
        }

        $packet = substr($this->channelBuffer, 4, $length);
        $this->channelBuffer = substr($this->channelBuffer, 4 + $length);

        return $packet;
    }

    /**
     * Обменивается identification strings (RFC 4253 §4.2).
     *
     * @param mixed $stream TCP stream resource
     *
     * @throws ConnectionException При ошибке I/O или не-SSH-2 ответе
     */
    private function exchangeIdentification(mixed $stream): void
    {
        if (!is_resource($stream)) {
            throw new ConnectionException('Ожидался stream resource для SSH');
        }

        if (@fwrite($stream, self::CLIENT_ID . "\r\n") === false) {
            throw new ConnectionException('SSH identification write не удался');
        }

        $line = '';

        while (!str_ends_with($line, "\n")) {
            $chunk = @fgets($stream, 512);

            if ($chunk === false) {
                throw new ConnectionException('SSH identification read не удался');
            }

            $line .= $chunk;
        }

        $this->serverId = trim($line);

        if (!str_starts_with($this->serverId, 'SSH-2.0-') && !str_starts_with($this->serverId, 'SSH-1.99-')) {
            throw new ConnectionException('Сервер не говорит SSH-2.0: ' . $this->serverId);
        }
    }

    /**
     * Выполняет KEX: group14-sha256, aes128-ctr, hmac-sha2-256 и включает шифрование.
     *
     * @throws ConnectionException При ошибке DH/NEWKEYS
     */
    private function performKeyExchange(): void
    {
        $clientKex = $this->buildClientKexInit();
        $this->wire->send($clientKex);
        $serverKex = $this->expectType(self::MSG_KEXINIT);
        [$eBytes, $fBytes, $hostKey, $kMpint] = $this->exchangeDiffieHellman();
        $hash = $this->computeExchangeHash($clientKex, $serverKex, $hostKey, $eBytes, $fBytes, $kMpint);
        $this->sessionId = $hash;
        $this->wire->send(chr(self::MSG_NEWKEYS));
        $this->expectType(self::MSG_NEWKEYS);
        $this->enableKeysFromSharedSecret($kMpint, $hash);
    }

    /**
     * Собирает SSH_MSG_KEXINIT клиента (group14-sha256 / aes128-ctr / hmac-sha2-256).
     */
    private function buildClientKexInit(): string
    {
        return chr(self::MSG_KEXINIT) . random_bytes(16)
            . SshBinary::nameList(['diffie-hellman-group14-sha256'])
            . SshBinary::nameList(['rsa-sha2-256', 'rsa-sha2-512', 'ssh-rsa', 'ssh-ed25519'])
            . SshBinary::nameList(['aes128-ctr'])
            . SshBinary::nameList(['aes128-ctr'])
            . SshBinary::nameList(['hmac-sha2-256'])
            . SshBinary::nameList(['hmac-sha2-256'])
            . SshBinary::nameList(['none'])
            . SshBinary::nameList(['none'])
            . SshBinary::nameList([])
            . SshBinary::nameList([])
            . "\x00"
            . SshBinary::uint32(0);
    }

    /**
     * DH group14: отправляет e, читает f/hostKey, считает K.
     *
     * @return array{0: string, 1: string, 2: string, 3: string} eBytes, fBytes, hostKey, K mpint
     *
     * @throws ConnectionException При некорректном f
     */
    private function exchangeDiffieHellman(): array
    {
        $prime = gmp_init(self::GROUP14_P, 16);
        $secret = gmp_random_bits(256);
        $public = gmp_powm(gmp_init(2), $secret, $prime);
        $eBytes = $this->gmpToBytes($public);
        $this->wire->send(chr(self::MSG_KEXDH_INIT) . SshBinary::mpint($eBytes));
        $reply = $this->expectType(self::MSG_KEXDH_REPLY);
        $offset = 1;
        $hostKey = SshBinary::readString($reply, $offset);
        $this->hostKeyBlob = $hostKey;
        $fBytes = SshBinary::readMpint($reply, $offset);
        SshBinary::readString($reply, $offset);

        if ($fBytes === '') {
            throw new ConnectionException('Некорректный DH параметр f');
        }

        $remotePublic = gmp_import($fBytes);

        if (gmp_cmp($remotePublic, 0) === 0) {
            throw new ConnectionException('Некорректный DH параметр f');
        }

        $shared = gmp_powm($remotePublic, $secret, $prime);

        return [$eBytes, $fBytes, $hostKey, SshBinary::mpint($this->gmpToBytes($shared))];
    }

    /**
     * Считает exchange hash H (SHA-256) по RFC 4253 / group14-sha256.
     */
    private function computeExchangeHash(
        string $clientKex,
        string $serverKex,
        string $hostKey,
        string $eBytes,
        string $fBytes,
        string $kMpint,
    ): string {
        return hash(
            'sha256',
            SshBinary::sshString(self::CLIENT_ID)
            . SshBinary::sshString($this->serverId)
            . SshBinary::sshString($clientKex)
            . SshBinary::sshString($serverKex)
            . SshBinary::sshString($hostKey)
            . SshBinary::mpint($eBytes)
            . SshBinary::mpint($fBytes)
            . $kMpint,
            true,
        );
    }

    /**
     * Выводит ключи AES/HMAC из K||H и включает шифрование на wire.
     */
    private function enableKeysFromSharedSecret(string $kMpint, string $hash): void
    {
        $this->wire->enableEncryption(
            $this->deriveKey($kMpint, $hash, 'C', 16),
            $this->deriveKey($kMpint, $hash, 'D', 16),
            $this->deriveKey($kMpint, $hash, 'A', 16),
            $this->deriveKey($kMpint, $hash, 'B', 16),
            $this->deriveKey($kMpint, $hash, 'E', 32),
            $this->deriveKey($kMpint, $hash, 'F', 32),
        );
    }

    /**
     * Запрашивает SSH service (обычно ssh-userauth).
     *
     * @param string $service Имя сервиса
     *
     * @throws ConnectionException При mismatch имени в SERVICE_ACCEPT
     */
    private function requestService(string $service): void
    {
        $this->wire->send(chr(self::MSG_SERVICE_REQUEST) . SshBinary::sshString($service));
        $payload = $this->expectType(self::MSG_SERVICE_ACCEPT);
        $offset = 1;

        if (SshBinary::readString($payload, $offset) !== $service) {
            throw new ConnectionException('SSH service accept mismatch');
        }
    }

    /**
     * Выбирает password или publickey по полям AuthCredentials.
     *
     * @param AuthCredentials $auth Учётные данные
     *
     * @throws ConnectionException Если нет ни пароля, ни ключа
     */
    private function authenticate(AuthCredentials $auth): void
    {
        if ($auth->privateKey() !== null && $auth->privateKey() !== '') {
            $this->authenticatePublicKey($auth);

            return;
        }

        if ($auth->password() !== null) {
            $this->authenticatePassword($auth);

            return;
        }

        throw new ConnectionException(
            'Для SSH нужны AuthCredentials::withPassword или AuthCredentials::withPublicKey',
        );
    }

    /**
     * USERAUTH password.
     *
     * @param AuthCredentials $auth Учётные данные с password
     *
     * @throws ConnectionException При отклонении или пустом пароле
     */
    private function authenticatePassword(AuthCredentials $auth): void
    {
        $password = $auth->password();

        if ($password === null) {
            throw new ConnectionException('Пустой пароль SSH');
        }

        $this->wire->send(
            chr(self::MSG_USERAUTH_REQUEST)
            . SshBinary::sshString($auth->username())
            . SshBinary::sshString('ssh-connection')
            . SshBinary::sshString('password')
            . "\x00"
            . SshBinary::sshString($password),
        );
        $this->assertAuthSuccess();
    }

    /**
     * USERAUTH publickey (RSA / Ed25519).
     *
     * @param AuthCredentials $auth Учётные данные с privateKey
     *
     * @throws ConnectionException При ошибке ключа или отклонении сервером
     */
    private function authenticatePublicKey(AuthCredentials $auth): void
    {
        $helper = new PublicKeySigner();
        $parts = $helper->load($auth);
        $this->wire->send($helper->buildRequest($this->sessionId, $auth->username(), $parts));
        $this->assertAuthSuccess();
    }

    /**
     * Ожидает USERAUTH_SUCCESS.
     *
     * @throws ConnectionException При FAILURE или неожиданном типе
     */
    private function assertAuthSuccess(): void
    {
        $response = $this->wire->receive();

        if ($response === '') {
            throw new ConnectionException('Пустой ответ USERAUTH');
        }

        $type = ord($response[0]);

        if ($type === self::MSG_USERAUTH_SUCCESS) {
            return;
        }

        if ($type === self::MSG_USERAUTH_FAILURE) {
            throw new ConnectionException('SSH-аутентификация отклонена');
        }

        throw new ConnectionException('Неожиданный USERAUTH type=' . $type);
    }

    /**
     * Открывает session-канал и поднимает SFTP subsystem + INIT.
     *
     * @throws ConnectionException Если VERSION не получен
     */
    private function prepareSftp(): void
    {
        if ($this->sftpReady) {
            return;
        }

        $this->openSessionChannel();
        $this->wire->send(
            chr(self::MSG_CHANNEL_REQUEST)
            . SshBinary::uint32($this->remoteChannel)
            . SshBinary::sshString('subsystem')
            . "\x01"
            . SshBinary::sshString('sftp'),
        );
        $this->expectType(self::MSG_CHANNEL_SUCCESS);
        $this->sendSftp(chr(1) . SshBinary::uint32(3));
        $init = $this->receiveSftp();

        if ($init === '' || ord($init[0]) !== 2) {
            throw new ConnectionException('SFTP VERSION не получен');
        }

        $this->sftpReady = true;
    }

    /**
     * Открывает session-канал, если ещё не открыт.
     *
     * @throws ConnectionException При отсутствии OPEN_CONFIRMATION
     */
    private function openSessionChannel(): void
    {
        if ($this->remoteChannel !== 0) {
            return;
        }

        $this->localChannel = 0;
        $this->channelBuffer = '';
        $this->channelEof = false;
        $this->wire->send(
            chr(self::MSG_CHANNEL_OPEN)
            . SshBinary::sshString('session')
            . SshBinary::uint32($this->localChannel)
            . SshBinary::uint32(2_097_152)
            . SshBinary::uint32(32_768),
        );
        $payload = $this->expectType(self::MSG_CHANNEL_OPEN_CONFIRMATION);
        $offset = 1;
        SshBinary::readUint32($payload, $offset);
        $this->remoteChannel = SshBinary::readUint32($payload, $offset);
    }

    /**
     * Копит CHANNEL_DATA до EOF/CLOSE и сбрасывает remote channel.
     *
     * @return string Накопленный вывод
     *
     * @throws ConnectionException При ошибке чтения
     */
    private function drainChannel(): string
    {
        $this->channelEof = false;

        while (!$this->channelEof) {
            $this->pump(true);
        }

        $output = $this->channelBuffer;
        $this->channelBuffer = '';
        $this->remoteChannel = 0;

        return $output;
    }

    /**
     * Читает один входящий пакет и обновляет channelBuffer / channelEof.
     *
     * @param bool $blocking true — всегда читать; false — пропуск, если буфер уже непуст
     *
     * @throws ConnectionException При ошибке wire->receive()
     */
    private function pump(bool $blocking): void
    {
        if (!$blocking && $this->channelBuffer !== '') {
            return;
        }

        $payload = $this->wire->receive();

        if ($payload === '') {
            return;
        }

        $type = ord($payload[0]);
        $offset = 1;

        if ($type === self::MSG_CHANNEL_DATA) {
            SshBinary::readUint32($payload, $offset);
            $this->channelBuffer .= SshBinary::readString($payload, $offset);
        } elseif ($type === self::MSG_CHANNEL_EOF || $type === self::MSG_CHANNEL_CLOSE) {
            $this->channelEof = true;
        }
    }

    /**
     * Читает пакет и проверяет message type.
     *
     * @param int $type Ожидаемый SSH message number
     *
     * @return string Полный payload пакета
     *
     * @throws ConnectionException При пустом ответе или другом type
     */
    private function expectType(int $type): string
    {
        $payload = $this->wire->receive();

        if ($payload === '' || ord($payload[0]) !== $type) {
            $actual = $payload === '' ? 'empty' : (string) ord($payload[0]);

            throw new ConnectionException(sprintf('Ожидался SSH type %d, получен %s', $type, $actual));
        }

        return $payload;
    }

    /**
     * Деривация ключей KEX (RFC 4253): HASH(K || H || letter || session_id).
     *
     * @param string $kMpint SSH mpint общего секрета K
     * @param string $h      Exchange hash H
     * @param string $letter Буква A…F назначения ключа
     * @param int    $length Нужная длина в байтах
     *
     * @return string Ровно $length байт ключевого материала
     */
    private function deriveKey(string $kMpint, string $h, string $letter, int $length): string
    {
        $key = hash('sha256', $kMpint . $h . $letter . $this->sessionId, true);

        while (strlen($key) < $length) {
            $key .= hash('sha256', $kMpint . $h . $key, true);
        }

        return substr($key, 0, $length);
    }

    /**
     * Сериализует GMP в беззнаковые big-endian байты.
     *
     * @param GMP $value Положительное целое
     *
     * @return string Бинарное представление или '' при сбое hex2bin
     */
    private function gmpToBytes(GMP $value): string
    {
        $hex = gmp_strval($value, 16);

        if ((strlen($hex) % 2) === 1) {
            $hex = '0' . $hex;
        }

        $bin = hex2bin($hex);

        return is_string($bin) ? $bin : '';
    }

    /**
     * Гарантирует, что сессия ещё подключена.
     *
     * @throws ConnectionException Если connected === false
     */
    private function assertConnected(): void
    {
        if (!$this->connected) {
            throw new ConnectionException('SSH-соединение закрыто');
        }
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function hostKeyFingerprint(): string
    {
        return base64_encode(hash('sha256', $this->hostKeyBlob, true));
    }

    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function remoteEndpoint(): string
    {
        return $this->remoteEndpoint;
    }

    /**
     * Привязывает логический endpoint к сессии.
     */
    public function bindEndpoint(string $endpoint): void
    {
        $this->remoteEndpoint = $endpoint;
    }
}
