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

    public function testRejectsNonResource(): void
    {
        $this->expectException(\CloudCastle\Ssh\Exception\ConnectionException::class);
        new NativeSession('nope', AuthCredentials::withPassword('u', 'p'));
    }
}
