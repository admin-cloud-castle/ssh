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
}