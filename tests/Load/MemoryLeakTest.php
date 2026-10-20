<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Load;

use CloudCastle\Ssh\Client\SessionFactory;
use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Pool\SessionPool;
use CloudCastle\Ssh\Security\EndpointGuard;
use CloudCastle\Ssh\Ssh;
use CloudCastle\Ssh\Tests\Support\MockSshPeer;
use CloudCastle\Ssh\Tools\PackageMemoryMeter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Контроль утечек памяти пакета под нагрузкой (emalloc − baseline).
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(PackageMemoryMeter::class)]
#[CoversClass(SessionFactory::class)]
#[CoversClass(SessionPool::class)]
#[CoversClass(Ssh::class)]
#[CoversClass(EndpointGuard::class)]
#[Group('load')]
final class MemoryLeakTest extends TestCase
{
    /**
     * После hold/release стека фасада остаточный прирост должен быть мал.
     */
    public function testFacadeStackHoldDoesNotLeak(): void
    {
        $guard = new EndpointGuard();
        $options = ConnectionOptions::defaults()->allowPrivateAddresses();

        $result = PackageMemoryMeter::measureHold(static function () use ($guard, $options) {
            return [
                new Ssh(),
                AuthCredentials::withPassword('deploy', 'secret'),
                $guard->prepare('127.0.0.1:22', $options),
            ];
        }, 200, 10);

        self::assertGreaterThan(0, $result['package_bytes']);
        self::assertSame(0, $result['leak_bytes']);
    }

    /**
     * Churn session+exec на mock-peer не должен копить память пакета.
     */
    public function testSessionExecChurnDoesNotLeak(): void
    {
        $factory = new SessionFactory();
        $auth = AuthCredentials::withPassword('u', 'p');
        $options = ConnectionOptions::defaults()->allowPrivateAddresses();

        $result = PackageMemoryMeter::measureChurn(static function () use ($factory, $auth, $options): void {
            [$port, $process] = MockSshPeer::listen();

            try {
                $session = $factory->connect('127.0.0.1:' . $port, $auth, $options);
                $session->exec('id');
                $session->disconnect();
            } finally {
                MockSshPeer::stop($process);
            }
        }, 12, 2);

        self::assertSame(0, $result['leak_bytes']);
    }

    /**
     * Churn SessionPool acquire/release не должен копить утечку.
     */
    public function testSessionPoolChurnDoesNotLeak(): void
    {
        $pool = new SessionPool(new SessionFactory(), 2);
        $auth = AuthCredentials::withPassword('u', 'p');
        $options = ConnectionOptions::defaults()->allowPrivateAddresses();

        // Каждый цикл — свой peer: mock обслуживает одно соединение до disconnect/drain.
        $result = PackageMemoryMeter::measureChurn(static function () use ($pool, $auth, $options): void {
            [$port, $process] = MockSshPeer::listen();
            $endpoint = '127.0.0.1:' . $port;

            try {
                $session = $pool->acquire($endpoint, $auth, $options);
                $session->exec('id');
                $pool->release($session);
                $pool->drain();
            } finally {
                MockSshPeer::stop($process);
            }
        }, 8, 2);

        self::assertSame(0, $result['leak_bytes']);
    }
}
