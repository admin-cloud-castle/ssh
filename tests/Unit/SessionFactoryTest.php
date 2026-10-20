<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Client\SessionFactory;
use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Security\EndpointGuard;
use CloudCastle\Ssh\Security\HostKeyVerifier;
use CloudCastle\Ssh\Tests\Support\MockSshPeer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionFactory::class)]
#[CoversClass(EndpointGuard::class)]
#[CoversClass(HostKeyVerifier::class)]
#[CoversClass(ConnectionOptions::class)]
#[CoversClass(AuthCredentials::class)]
#[CoversClass(\CloudCastle\Ssh\Security\PinnedEndpoint::class)]
#[CoversClass(\CloudCastle\Ssh\Session\NativeSession::class)]
#[CoversClass(\CloudCastle\Ssh\Wire\SshBinary::class)]
#[CoversClass(\CloudCastle\Ssh\Wire\SshWire::class)]
final class SessionFactoryTest extends TestCase
{
    public function testConnectToMockServer(): void
    {
        [$port, $process] = MockSshPeer::listen();

        try {
            $factory = new SessionFactory();
            $session = $factory->connect(
                '127.0.0.1:' . $port,
                AuthCredentials::withPassword('u', 'p'),
                ConnectionOptions::defaults()->allowPrivateAddresses(),
            );
            self::assertTrue($session->isConnected());
            self::assertSame('127.0.0.1:' . $port, $session->remoteEndpoint());
            self::assertStringContainsString('mock:', $session->exec('id'));
            $session->disconnect();
        } finally {
            MockSshPeer::stop($process);
        }
    }

    public function testConnectFailure(): void
    {
        $factory = new SessionFactory();
        $this->expectException(ConnectionException::class);
        $factory->connect(
            '127.0.0.1:1',
            AuthCredentials::withPassword('u', 'p'),
            ConnectionOptions::defaults()->allowPrivateAddresses(),
        );
    }
}
