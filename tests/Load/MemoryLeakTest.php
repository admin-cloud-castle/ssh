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
 * Размеры совпадают с tools/benchmark-memory.php: hold×400, churn×25 / pool×12.
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
     * Число объектов в hold-сценарии (как в benchmark-memory.php).
     */
    private const HOLD_ITERATIONS = 400;

    /**
     * Число session+exec churn-итераций.
     */
    private const CHURN_SESSIONS = 25;

    /**
     * Число SessionPool churn-итераций.
     */
    private const CHURN_POOL = 12;

    /**
     * После hold/release стека фасада остаточный прирост должен быть 0.
     */
    public function testFacadeStackHoldDoesNotLeak(): void
    {
        $guard = new EndpointGuard();
        $options = ConnectionOptions::defaults()->allowPrivateAddresses();

        $result = PackageMemoryMeter::measureHold(static fn (): array => [
            new Ssh(),
            AuthCredentials::withPassword('deploy', 'secret'),
            $guard->prepare('127.0.0.1:22', $options),
        ], self::HOLD_ITERATIONS, 15);

        self::assertGreaterThan(0, $result['package_bytes']);
        self::assertSame(
            0,
            $result['leak_bytes'],
            'hold×' . self::HOLD_ITERATIONS . ' дал leak_bytes=' . $result['leak_bytes'],
        );
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
        }, self::CHURN_SESSIONS, 5);

        self::assertSame(0, $result['leak_bytes'], 'session churn leak_bytes=' . $result['leak_bytes']);
    }

    /**
     * Churn SessionPool acquire/release/drain не должен копить утечку.
     */
    public function testSessionPoolChurnDoesNotLeak(): void
    {
        $pool = new SessionPool(new SessionFactory(), 2);
        $auth = AuthCredentials::withPassword('u', 'p');
        $options = ConnectionOptions::defaults()->allowPrivateAddresses();

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
        }, self::CHURN_POOL, 4);

        self::assertSame(0, $result['leak_bytes'], 'pool churn leak_bytes=' . $result['leak_bytes']);
    }
}
