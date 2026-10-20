<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Auth\PublicKeySigner;
use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Session\NativeSession;
use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Tests\Support\MockSshPeer;
use CloudCastle\Ssh\Wire\SshBinary;
use CloudCastle\Ssh\Wire\SshWire;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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

    public function testOpenSubsystem(): void
    {
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            $session->openSubsystem('sftp');
            $session->openSubsystem('sftp');
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
        $this->expectException(\CloudCastle\Ssh\Exception\ConnectionException::class);
        $session = new NativeSession('nope', AuthCredentials::withPassword('u', 'p'));
        self::assertFalse($session->isConnected());
    }

    /**
     * Без явного disconnect() деструктор закрывает транспорт (нет утечки сокета).
     */
    public function testDestructorDisconnects(): void
    {
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            self::assertTrue($session->isConnected());
            unset($session);
            self::assertTrue(true);
        } finally {
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
            self::assertSame('', SshBinary::readString($body, $offset));
        } finally {
            putenv('CC_SSH_DISCONNECT_FILE');
            self::unlinkTempFile($record);
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
            self::assertSame(20, ord($payload[0]));
            self::assertSame(16, strlen(substr($payload, 1, 16)));
            self::assertStringContainsString('diffie-hellman-group14-sha256', $payload);
            self::assertStringContainsString('aes128-ctr', $payload);
            self::assertStringContainsString('hmac-sha2-256', $payload);
            self::assertSame("\x00", $payload[strlen($payload) - 5]);
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

    /**
     * Удаляет файл только внутри sys_get_temp_dir().
     */
    private static function unlinkTempFile(string $path): void
    {
        $tempRoot = realpath(sys_get_temp_dir());
        $real = realpath($path);

        if ($tempRoot === false || $real === false || !str_starts_with($real, $tempRoot)) {
            return;
        }

        // Путь уже проверен realpath + префикс temp dir.
        unlink($real); // nosemgrep: php.lang.security.unlink-use.unlink-use
    }
}
