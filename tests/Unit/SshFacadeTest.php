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
use ReflectionProperty;

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
        $this->expectException(SshException::class);
        $ssh->abort('x');
    }

    public function testConnectUsesFactory(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('exec')->willReturnCallback(static function (string $cmd): string {
            if (str_contains($cmd, 'scp -f')) {
                return 'payload';
            }

            return base64_encode('pong');
        });
        $factory = $this->createMock(SessionFactoryInterface::class);
        $factory->expects(self::once())->method('connect')->willReturn($session);
        $ssh = new Ssh($factory);
        $auth = AuthCredentials::withPassword('u', 'p');
        self::assertSame(
            $session,
            $ssh->connect('h', $auth, ConnectionOptions::defaults()->allowPrivateAddresses()),
        );
        self::assertSame($factory, $ssh->factory());
        self::assertSame(0, $ssh->pool()->size());
        self::assertSame(42, $ssh->async()->run(static fn (): int => 42));
        $mapped = iterator_to_array($ssh->processes()->map([3], static fn (int $value): int => $value * 2), false);
        self::assertSame([6], $mapped);
        self::assertSame('payload', $ssh->scp($session)->get('/tmp/x'));
        self::assertSame('pong', $ssh->forward($session)->request('127.0.0.1', 80, 'ping'));
    }

    public function testDefaultWorkerCount(): void
    {
        $ssh = new Ssh();
        $processPool = $ssh->processes();
        $workers = new ReflectionProperty(ProcessPool::class, 'workers');
        self::assertSame(2, $workers->getValue($processPool));
    }

    public function testLazySlotsReturnSameInstances(): void
    {
        $ssh = new Ssh(null, 5);
        $poolFirst = $ssh->pool();
        $poolSecond = $ssh->pool();
        self::assertSame($poolFirst, $poolSecond);
        self::assertInstanceOf(SessionPool::class, $poolFirst);

        $asyncFirst = $ssh->async();
        $asyncSecond = $ssh->async();
        self::assertSame($asyncFirst, $asyncSecond);
        self::assertInstanceOf(FiberAsyncRuntime::class, $asyncFirst);

        $processFirst = $ssh->processes();
        $processSecond = $ssh->processes();
        self::assertSame($processFirst, $processSecond);

        $workers = new ReflectionProperty(ProcessPool::class, 'workers');
        $workerCount = $workers->getValue($processFirst);
        self::assertSame(5, $workerCount);
    }

    public function testFactorySlotInjectedIsReused(): void
    {
        $factory = $this->createMock(SessionFactoryInterface::class);
        $ssh = new Ssh($factory);
        self::assertSame($factory, $ssh->factory());
        self::assertSame($factory, $ssh->factory());
    }
}
