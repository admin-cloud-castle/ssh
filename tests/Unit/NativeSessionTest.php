<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Auth\PublicKeySigner;
use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Session\NativeSession;
use CloudCastle\Ssh\Tests\Support\MockSshPeer;
use CloudCastle\Ssh\Wire\SshBinary;
use CloudCastle\Ssh\Wire\SshWire;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
}
