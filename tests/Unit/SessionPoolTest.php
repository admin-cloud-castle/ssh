<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Contract\SessionFactoryInterface;
use CloudCastle\Ssh\Contract\SessionInterface;
use CloudCastle\Ssh\Pool\SessionPool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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
#[CoversClass(SessionPool::class)]
#[CoversClass(AuthCredentials::class)]
#[CoversClass(ConnectionOptions::class)]
final class SessionPoolTest extends TestCase
{
    public function testAcquireReleaseDrain(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('isConnected')->willReturn(true);
        $session->method('remoteEndpoint')->willReturn('h:22');
        $factory = $this->createMock(SessionFactoryInterface::class);
        $factory->expects(self::once())->method('connect')->willReturn($session);
        $pool = new SessionPool($factory, 2);
        $auth = AuthCredentials::withPassword('u', 'p');
        $got = $pool->acquire('h:22', $auth, ConnectionOptions::defaults()->allowPrivateAddresses());
        self::assertSame($session, $got);
        $pool->release($session);
        self::assertSame(1, $pool->size());
        $got2 = $pool->acquire('h:22', $auth);
        self::assertSame($session, $got2);
        $pool->release($session);
        $pool->drain();
        self::assertSame(0, $pool->size());
    }

    public function testReleaseClosedIgnored(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('isConnected')->willReturn(false);
        $pool = new SessionPool($this->createMock(SessionFactoryInterface::class));
        $pool->release($session);
        self::assertSame(0, $pool->size());
    }

    public function testOverflowDisconnects(): void
    {
        $s1 = $this->createMock(SessionInterface::class);
        $s1->method('isConnected')->willReturn(true);
        $s1->method('remoteEndpoint')->willReturn('h');
        $s1->expects(self::never())->method('disconnect');
        $s2 = $this->createMock(SessionInterface::class);
        $s2->method('isConnected')->willReturn(true);
        $s2->method('remoteEndpoint')->willReturn('h');
        $s2->expects(self::once())->method('disconnect');
        $pool = new SessionPool($this->createMock(SessionFactoryInterface::class), 1);
        $pool->release($s1);
        $pool->release($s2);
        self::assertSame(1, $pool->size());
    }

    public function testMaxPerKeyZeroDefaultsToEight(): void
    {
        $factory = $this->createMock(SessionFactoryInterface::class);
        $factory->expects(self::never())->method('connect');
        $pool = new SessionPool($factory, 0);

        for ($index = 0; $index < 8; ++$index) {
            $session = $this->createMock(SessionInterface::class);
            $session->method('isConnected')->willReturn(true);
            $session->method('remoteEndpoint')->willReturn('host:22');
            $pool->release($session);
        }

        self::assertSame(8, $pool->size());

        $overflow = $this->createMock(SessionInterface::class);
        $overflow->method('isConnected')->willReturn(true);
        $overflow->method('remoteEndpoint')->willReturn('host:22');
        $overflow->expects(self::once())->method('disconnect');
        $pool->release($overflow);
        self::assertSame(8, $pool->size());
    }

    public function testEndpointKeyIsCaseInsensitive(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('isConnected')->willReturn(true);
        $session->method('remoteEndpoint')->willReturn('HOST:22');
        $factory = $this->createMock(SessionFactoryInterface::class);
        $factory->expects(self::never())->method('connect');
        $pool = new SessionPool($factory, 2);
        $pool->release($session);
        $got = $pool->acquire('host:22', AuthCredentials::withPassword('u', 'p'));
        self::assertSame($session, $got);
    }

    public function testAcquireDisconnectsStaleIdle(): void
    {
        $dead = $this->createMock(SessionInterface::class);
        $dead->method('isConnected')->willReturn(false);
        $dead->expects(self::once())->method('disconnect');
        $alive = $this->createMock(SessionInterface::class);
        $alive->method('isConnected')->willReturn(true);
        $alive->method('remoteEndpoint')->willReturn('h');
        $factory = $this->createMock(SessionFactoryInterface::class);
        $factory->expects(self::never())->method('connect');
        $pool = new SessionPool($factory, 2);
        $pool->release($alive);
        $ref = new \ReflectionProperty(SessionPool::class, 'idle');
        $ref->setValue($pool, ['h' => [$alive, $dead]]);
        $got = $pool->acquire('H', AuthCredentials::withPassword('u', 'p'));
        self::assertSame($alive, $got);
    }

    public function testDrainClosesAllBuckets(): void
    {
        $first = $this->createMock(SessionInterface::class);
        $first->method('isConnected')->willReturn(true);
        $first->method('remoteEndpoint')->willReturn('a:22');
        $first->expects(self::once())->method('disconnect');
        $second = $this->createMock(SessionInterface::class);
        $second->method('isConnected')->willReturn(true);
        $second->method('remoteEndpoint')->willReturn('b:22');
        $second->expects(self::once())->method('disconnect');
        $pool = new SessionPool($this->createMock(SessionFactoryInterface::class), 4);
        $pool->release($first);
        $pool->release($second);
        self::assertSame(2, $pool->size());
        $pool->drain();
        self::assertSame(0, $pool->size());
    }

    public function testSizeSumsAllBuckets(): void
    {
        $pool = new SessionPool($this->createMock(SessionFactoryInterface::class), 4);

        foreach (['alpha:22', 'beta:22', 'alpha:22'] as $endpoint) {
            $session = $this->createMock(SessionInterface::class);
            $session->method('isConnected')->willReturn(true);
            $session->method('remoteEndpoint')->willReturn($endpoint);
            $pool->release($session);
        }

        self::assertSame(3, $pool->size());
    }
}
