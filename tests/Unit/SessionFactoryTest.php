<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Client\SessionFactory;
use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Exception\SecurityException;
use CloudCastle\Ssh\Security\EndpointGuard;
use CloudCastle\Ssh\Security\HostKeyVerifier;
use CloudCastle\Ssh\Security\PinnedEndpoint;
use CloudCastle\Ssh\Tests\Support\MockSshPeer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

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
        $this->expectExceptionMessageMatches('/^TCP к 127\.0\.0\.1:1 не удался: \[\d+\] /');
        $factory->connect(
            '127.0.0.1:1',
            AuthCredentials::withPassword('u', 'p'),
            ConnectionOptions::defaults()->allowPrivateAddresses(),
        );
    }

    public function testConnectViaTcpUrlScheme(): void
    {
        [$port, $process] = MockSshPeer::listen();

        try {
            $factory = new SessionFactory();
            $session = $factory->connect(
                'tcp://127.0.0.1:' . $port,
                AuthCredentials::withPassword('u', 'p'),
                ConnectionOptions::defaults()->allowPrivateAddresses(),
            );
            self::assertTrue($session->isConnected());
            self::assertSame('tcp://127.0.0.1:' . $port, $session->remoteEndpoint());
            $session->disconnect();
        } finally {
            MockSshPeer::stop($process);
        }
    }

    public function testBuildTargetPortFallbackAndIpv6Literal(): void
    {
        $factory = new SessionFactory();
        $build = new ReflectionMethod(SessionFactory::class, 'buildTarget');
        $zeroPort = new PinnedEndpoint('h', 'h', ['1.2.3.4'], 0);
        $built = $build->invoke($factory, $zeroPort, 'h');
        self::assertIsArray($built);
        self::assertArrayHasKey(1, $built);
        self::assertArrayHasKey(2, $built);
        self::assertSame(22, $built[1]);
        self::assertSame('tcp://1.2.3.4:22', $built[2]);

        $tcp = new ReflectionMethod(SessionFactory::class, 'tcpTarget');
        self::assertSame('tcp://[2001:db8::1]:22', $tcp->invoke($factory, '2001:db8::1', 22));
        self::assertSame('tcp://host.example:9', $tcp->invoke($factory, 'host.example', 9));
    }

    public function testConnectRejectsBlockedPrivateWithoutAllow(): void
    {
        $factory = new SessionFactory();
        $this->expectException(SecurityException::class);
        $factory->connect(
            '127.0.0.1:22',
            AuthCredentials::withPassword('u', 'p'),
            ConnectionOptions::defaults(),
        );
    }
}
