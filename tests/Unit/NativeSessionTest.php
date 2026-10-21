<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Auth\PublicKeySigner;
use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Session\NativeSession;
use CloudCastle\Ssh\Tests\Support\MockSshPeer;
use CloudCastle\Ssh\Tests\Support\TempPath;
use CloudCastle\Ssh\Wire\SshBinary;
use CloudCastle\Ssh\Wire\SshWire;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Интеграция NativeSession с MockSshServer (TCP peer-процесс).
 */
/**
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.ShortVariable")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.MissingImport")
 * @SuppressWarnings("PHPMD.ExcessivePublicCount")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 * @SuppressWarnings("PHPMD.UnusedLocalVariable")
 */
#[CoversClass(NativeSession::class)]
#[CoversClass(SshWire::class)]
#[CoversClass(SshBinary::class)]
#[CoversClass(AuthCredentials::class)]
#[CoversClass(PublicKeySigner::class)]
final class NativeSessionTest extends TestCase
{
    public function testPasswordAuthExecAndDisconnect(): void
    {
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            self::assertTrue($session->isConnected());
            self::assertNotSame('', $session->hostKeyFingerprint());
            $out = $session->exec('uname -a');
            self::assertStringContainsString('mock:uname -a', $out);
            $session->bindEndpoint('mock://local');
            self::assertSame('mock://local', $session->remoteEndpoint());
            $session->disconnect();
            self::assertFalse($session->isConnected());
            $session->disconnect();
        } finally {
            MockSshPeer::stop($process);
        }
    }

    public function testHostKeyAvailableBeforeLogin(): void
    {
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream);
            self::assertFalse($session->isConnected());
            self::assertNotSame('', $session->hostKeyFingerprint());
            $session->login(AuthCredentials::withPassword('user', 'secret'));
            self::assertTrue($session->isConnected());
            $this->expectException(ConnectionException::class);
            $this->expectExceptionMessage('SSH-сессия уже аутентифицирована');
            $session->login(AuthCredentials::withPassword('user', 'secret'));
        } finally {
            MockSshPeer::stop($process);
        }
    }

    public function testOpenSubsystem(): void
    {
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            $session->openSubsystem('sftp');
            $session->openSubsystem('sftp');
            $remote = new ReflectionProperty(NativeSession::class, 'remoteChannel');
            self::assertSame(1, $remote->getValue($session));
            $subsystem = new ReflectionProperty(NativeSession::class, 'subsystem');
            self::assertSame('sftp', $subsystem->getValue($session));
            self::assertTrue($session->isConnected());
            $session->disconnect();
        } finally {
            MockSshPeer::stop($process);
        }
    }

    public function testWriteAndReadSessionChannel(): void
    {
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            $session->write('hello');
            self::assertSame('echo:hello', $session->read(32));
            $session->disconnect();
        } finally {
            MockSshPeer::stop($process);
        }
    }

    public function testRejectsNonResource(): void
    {
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Ожидался stream resource для SSH');
        $stream = 'nope';
        $session = new NativeSession($stream, AuthCredentials::withPassword('u', 'p'));
        self::assertFalse($session->isConnected());
    }

    /**
     * Без явного disconnect() деструктор закрывает транспорт (нет утечки сокета).
     */
    public function testDestructorDisconnects(): void
    {
        $record = tempnam(sys_get_temp_dir(), 'ccsshdc');
        self::assertNotFalse($record);
        putenv('CC_SSH_DISCONNECT_FILE=' . $record);
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            self::assertTrue($session->isConnected());
            unset($session);
            usleep(50_000);
            $body = (string) file_get_contents($record);
            self::assertNotSame('', $body);
            self::assertSame("\x00\x00\x00\x00", substr($body, -4));
        } finally {
            putenv('CC_SSH_DISCONNECT_FILE');
            $this->unlinkTempFile($record);
            MockSshPeer::stop($process);
        }
    }

    public function testDisconnectSendsExactDisconnectPayload(): void
    {
        $record = tempnam(sys_get_temp_dir(), 'ccsshdc');
        self::assertNotFalse($record);
        putenv('CC_SSH_DISCONNECT_FILE=' . $record);
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            $session->exec('sync');
            $session->disconnect();
            self::assertFalse($session->isConnected());
            usleep(50_000);
            $body = (string) file_get_contents($record);
            $offset = 0;
            self::assertSame(11, SshBinary::readUint32($body, $offset));
            self::assertSame('bye', SshBinary::readString($body, $offset));
            self::assertSame("\x00\x00\x00\x00", substr($body, $offset));
            self::assertSame(strlen($body), $offset + 4);
        } finally {
            putenv('CC_SSH_DISCONNECT_FILE');
            $this->unlinkTempFile($record);
            MockSshPeer::stop($process);
        }
    }

    public function testBuildClientKexInitStructure(): void
    {
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            $method = new ReflectionMethod(NativeSession::class, 'buildClientKexInit');
            $payload = $method->invoke($session);
            self::assertIsString($payload);
            [$lists, $follows, $reserved] = $this->parseKexInit($payload);
            self::assertSame('diffie-hellman-group14-sha256', $lists[0]);
            self::assertSame('rsa-sha2-256,rsa-sha2-512,ssh-rsa,ssh-ed25519', $lists[1]);
            self::assertSame('aes128-ctr', $lists[2]);
            self::assertSame('aes128-ctr', $lists[3]);
            self::assertSame('hmac-sha2-256', $lists[4]);
            self::assertSame('hmac-sha2-256', $lists[5]);
            self::assertSame('none', $lists[6]);
            self::assertSame('none', $lists[7]);
            self::assertSame('', $lists[8]);
            self::assertSame('', $lists[9]);
            self::assertSame(0, $follows);
            self::assertSame(0, $reserved);
            $session->disconnect();
        } finally {
            MockSshPeer::stop($process);
        }
    }

    public function testReadDefaultMaxLength(): void
    {
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            $session->write(str_repeat('a', 9000));
            $chunk = $session->read();
            self::assertSame(8192, strlen($chunk));
            self::assertSame('echo:' . str_repeat('a', 8187), $chunk);
            $session->disconnect();
        } finally {
            MockSshPeer::stop($process);
        }
    }

    public function testReadWithZeroMaxLengthReturnsOneByte(): void
    {
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            $session->write('xy');
            self::assertSame('e', $session->read(0));
            $session->disconnect();
        } finally {
            MockSshPeer::stop($process);
        }
    }

    public function testExecAfterDisconnectMessagePrefix(): void
    {
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            $session->disconnect();
            $this->expectException(ConnectionException::class);
            $this->expectExceptionMessage('SSH-соединение закрыто');
            $session->exec('id');
        } finally {
            MockSshPeer::stop($process);
        }
    }

    public function testAcceptsSsh199Identification(): void
    {
        putenv('CC_SSH_SERVER_ID=SSH-1.99-CloudCastle_Mock');
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            self::assertTrue($session->isConnected());
            $session->disconnect();
        } finally {
            putenv('CC_SSH_SERVER_ID');
            MockSshPeer::stop($process);
        }
    }

    public function testRejectsNonSsh2Identification(): void
    {
        putenv('CC_SSH_SERVER_ID=SSH-1.5-legacy');
        [$stream, $process] = MockSshPeer::start();

        try {
            $this->expectException(ConnectionException::class);
            $this->expectExceptionMessage('Сервер не говорит SSH-2.0');
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            self::assertFalse($session->isConnected());
        } finally {
            putenv('CC_SSH_SERVER_ID');
            MockSshPeer::stop($process);
        }
    }

    public function testDisconnectWipesChannelState(): void
    {
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            $session->write('x');
            $remote = new ReflectionProperty(NativeSession::class, 'remoteChannel');
            self::assertNotSame(0, $remote->getValue($session));
            $session->disconnect();
            self::assertSame(0, $remote->getValue($session));
            $eof = new ReflectionProperty(NativeSession::class, 'channelEof');
            self::assertFalse($eof->getValue($session));
            $serverId = new ReflectionProperty(NativeSession::class, 'serverId');
            self::assertSame('', $serverId->getValue($session));
        } finally {
            MockSshPeer::stop($process);
        }
    }

    public function testPumpIgnoresNonChannelPayload(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$client, $server] = $pair;
        $session = (new ReflectionClass(NativeSession::class))->newInstanceWithoutConstructor();
        $wire = new ReflectionProperty(NativeSession::class, 'wire');
        $wire->setValue($session, new SshWire($client));
        $buffer = new ReflectionProperty(NativeSession::class, 'channelBuffer');
        $buffer->setValue($session, '');
        $eof = new ReflectionProperty(NativeSession::class, 'channelEof');
        $eof->setValue($session, false);
        $this->writePlainPacket($server, chr(20));
        $pump = new ReflectionMethod(NativeSession::class, 'pump');
        $pump->invoke($session, true);
        self::assertFalse($eof->getValue($session));
        self::assertSame('', $buffer->getValue($session));
        fclose($client);
        fclose($server);
    }

    public function testGmpToBytesPadsOddHex(): void
    {
        $session = (new ReflectionClass(NativeSession::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(NativeSession::class, 'gmpToBytes');
        $bytes = $method->invoke($session, gmp_init(15));
        self::assertSame("\x0f", $bytes);
    }

    public function testChannelBufferConcatenatesTwoDataPackets(): void
    {
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            $session->write('A');
            $session->write('B');
            $pump = new ReflectionMethod(NativeSession::class, 'pump');
            $pump->invoke($session, true);
            $pump->invoke($session, true);
            $buffer = new ReflectionProperty(NativeSession::class, 'channelBuffer');
            self::assertSame('echo:Aecho:B', $buffer->getValue($session));
            $session->disconnect();
        } finally {
            MockSshPeer::stop($process);
        }
    }

    /**
     * Разбирает SSH_MSG_KEXINIT: cookie, 10 name-list, first_kex_packet_follows, reserved.
     *
     * @return array{0: list<string>, 1: int, 2: int}
     */
    private function parseKexInit(string $payload): array
    {
        self::assertSame(20, ord($payload[0]));
        $offset = 1;
        $cookie = substr($payload, $offset, 16);
        self::assertSame(16, strlen($cookie));
        $offset += 16;
        $lists = [];

        for ($index = 0; $index < 10; $index++) {
            $lists[] = SshBinary::readString($payload, $offset);
        }

        self::assertTrue(isset($payload[$offset]));
        $follows = ord($payload[$offset]);
        $offset++;
        $reserved = SshBinary::readUint32($payload, $offset);
        self::assertSame(strlen($payload), $offset);

        return [$lists, $follows, $reserved];
    }

    /**
     * Пишет plaintext SSH binary packet на stream.
     *
     * @param resource $stream Приёмник
     * @param string   $payload Тело пакета
     */
    private function writePlainPacket($stream, string $payload): void
    {
        $block = 8;
        $payloadLength = strlen($payload);
        $paddingLength = $block - (($payloadLength + 5) % $block);

        if ($paddingLength < 4) {
            $paddingLength += $block;
        }

        $packetLength = $payloadLength + $paddingLength + 1;
        fwrite(
            $stream,
            SshBinary::uint32($packetLength)
            . chr($paddingLength)
            . $payload
            . str_repeat("\x00", $paddingLength),
        );
    }

    /**
     * Удаляет файл только внутри sys_get_temp_dir().
     */
    private function unlinkTempFile(string $path): void
    {
        TempPath::unlinkIfTemp($path);
    }
}
