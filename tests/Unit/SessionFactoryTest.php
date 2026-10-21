<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Client\SessionFactory;
use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Exception\HostKeyException;
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

    public function testConnectRejectsMismatchedHostKey(): void
    {
        [$port, $process] = MockSshPeer::listen();

        try {
            $factory = new SessionFactory();
            $this->expectException(HostKeyException::class);
            $factory->connect(
                '127.0.0.1:' . $port,
                AuthCredentials::withPassword('u', 'p'),
                ConnectionOptions::defaults()
                    ->allowPrivateAddresses()
                    ->withTrustedHostKeys(['definitely-not-the-mock-fingerprint']),
            );
        } finally {
            MockSshPeer::stop($process);
        }
    }

    public function testPositiveTimeoutSecondsFloorAndCast(): void
    {
        $factory = new SessionFactory();
        $method = new ReflectionMethod(SessionFactory::class, 'positiveTimeoutSeconds');
        self::assertSame(1, $method->invoke($factory, 0.0));
        self::assertSame(1, $method->invoke($factory, 0.9));
        self::assertSame(1, $method->invoke($factory, 1.0));
        self::assertSame(2, $method->invoke($factory, 2.9));
        self::assertSame(30, $method->invoke($factory, 30.0));
    }

    public function testInitialSocketErrnoIsZero(): void
    {
        $factory = new SessionFactory();
        $method = new ReflectionMethod(SessionFactory::class, 'initialSocketErrno');
        self::assertSame(0, $method->invoke($factory));
    }

    public function testApplyIoTimeoutSetsTimeoutOnStream(): void
    {
        $factory = new SessionFactory();
        $method = new ReflectionMethod(SessionFactory::class, 'applyIoTimeout');
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$left, $right] = $pair;
        $method->invoke($factory, $left, 3);
        self::assertTrue(is_resource($left));
        fclose($left);
        fclose($right);
    }

    public function testApplyIoTimeoutThrowsWhenSetTimeoutFails(): void
    {
        $factory = new SessionFactory();
        $method = new ReflectionMethod(SessionFactory::class, 'applyIoTimeout');
        $stream = tmpfile();
        self::assertNotFalse($stream);
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Не удалось выставить I/O timeout SSH stream');

        try {
            $method->invoke($factory, $stream, 1);
        } finally {
            fclose($stream);
        }
    }

    public function testApplyIoTimeoutRejectsNonResource(): void
    {
        $factory = new SessionFactory();
        $method = new ReflectionMethod(SessionFactory::class, 'applyIoTimeout');
        $this->expectException(ConnectionException::class);
        $method->invoke($factory, 'not-a-stream', 1);
    }

    public function testTargetFromUrlParsesHostAndFallsBackWithoutHost(): void
    {
        $factory = new SessionFactory();
        $method = new ReflectionMethod(SessionFactory::class, 'targetFromUrl');
        $parsed = $method->invoke($factory, 'tcp://example.test:2222', 'fallback', 22);
        self::assertSame(['example.test', 2222, 'tcp://example.test:2222'], $parsed);
        $bracketed = $method->invoke($factory, 'tcp://[::1]:2222', 'fallback', 22);
        self::assertSame(['::1', 2222, 'tcp://[::1]:2222'], $bracketed);
        $fallback = $method->invoke($factory, 'tcp://', 'fallback.host', 99);
        self::assertSame(['fallback.host', 99, 'tcp://fallback.host:99'], $fallback);
        $noHost = $method->invoke($factory, 'not-a-url-scheme://', 'fb', 7);
        self::assertIsArray($noHost);
        self::assertSame('fb', $noHost[0]);
        self::assertSame(7, $noHost[1]);
    }

    public function testUnwrapBracketHostOnlyWhenBothBracketsPresent(): void
    {
        $factory = new SessionFactory();
        $method = new ReflectionMethod(SessionFactory::class, 'unwrapBracketHost');
        self::assertSame('::1', $method->invoke($factory, '[::1]'));
        self::assertSame('[::1', $method->invoke($factory, '[::1'));
        self::assertSame('::1]', $method->invoke($factory, '::1]'));
        self::assertSame('example.test', $method->invoke($factory, 'example.test'));
    }

    public function testNeedsIpv6BracketsForIpv6AndColonHost(): void
    {
        $factory = new SessionFactory();
        $method = new ReflectionMethod(SessionFactory::class, 'needsIpv6Brackets');
        self::assertTrue($method->invoke($factory, '2001:db8::1'));
        self::assertTrue($method->invoke($factory, 'dead:beef'));
        self::assertFalse($method->invoke($factory, '192.0.2.1'));
        self::assertFalse($method->invoke($factory, 'example.test'));
    }

    public function testTcpTargetWrapsColonHostsInBrackets(): void
    {
        $factory = new SessionFactory();
        $method = new ReflectionMethod(SessionFactory::class, 'tcpTarget');
        self::assertSame('tcp://[2001:db8::1]:22', $method->invoke($factory, '2001:db8::1', 22));
        self::assertSame('tcp://[2001:db8::1]:22', $method->invoke($factory, '[2001:db8::1]', 22));
        self::assertSame('tcp://[dead:beef]:9', $method->invoke($factory, 'dead:beef', 9));
        self::assertSame('tcp://example.test:9', $method->invoke($factory, 'example.test', 9));
    }
}
