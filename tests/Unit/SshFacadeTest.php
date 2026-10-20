<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Async\FiberAsyncRuntime;
use CloudCastle\Ssh\Client\SessionFactory;
use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Contract\SessionFactoryInterface;
use CloudCastle\Ssh\Contract\SessionInterface;
use CloudCastle\Ssh\Exception\SshException;
use CloudCastle\Ssh\Forward\LocalPortForward;
use CloudCastle\Ssh\Pool\SessionPool;
use CloudCastle\Ssh\Process\ProcessPool;
use CloudCastle\Ssh\Scp\ScpClient;
use CloudCastle\Ssh\Ssh;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ssh::class)]
#[CoversClass(SessionPool::class)]
#[CoversClass(FiberAsyncRuntime::class)]
#[CoversClass(ProcessPool::class)]
#[CoversClass(ScpClient::class)]
#[CoversClass(LocalPortForward::class)]
#[CoversClass(AuthCredentials::class)]
#[CoversClass(ConnectionOptions::class)]
#[CoversClass(SessionFactory::class)]
final class SshFacadeTest extends TestCase
{
    public function testNameAndAbort(): void
    {
        $ssh = new Ssh();
        self::assertSame('cloud-castle/ssh', $ssh->name());
        self::assertSame('1.0.0', Ssh::VERSION);
        $this->expectException(SshException::class);
        $ssh->abort('x');
    }

    public function testConnectUsesFactory(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $factory = $this->createMock(SessionFactoryInterface::class);
        $factory->expects(self::once())->method('connect')->willReturn($session);
        $ssh = new Ssh($factory);
        $auth = AuthCredentials::withPassword('u', 'p');
        self::assertSame($session, $ssh->connect('h', $auth, ConnectionOptions::defaults()->allowPrivateAddresses()));
        self::assertSame($factory, $ssh->factory());
        self::assertSame(0, $ssh->pool()->size());
        self::assertInstanceOf(FiberAsyncRuntime::class, $ssh->async());
        self::assertInstanceOf(ProcessPool::class, $ssh->processes());
        self::assertInstanceOf(ScpClient::class, $ssh->scp($session));
        self::assertInstanceOf(LocalPortForward::class, $ssh->forward($session));
    }
}