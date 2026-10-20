<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Security\PhpDnsResolver;
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
#[CoversClass(PhpDnsResolver::class)]
final class PhpDnsResolverTest extends TestCase
{
    public function testResolveLocalOrEmpty(): void
    {
        $dns = new PhpDnsResolver();
        $ips = $dns->resolve('127.0.0.1');
        self::assertSame(['127.0.0.1'], $ips);
        $local = $dns->resolve('localhost');
        self::assertContainsOnly('string', $local);
    }

    public function testFromDnsRecordsDedupesViaReflection(): void
    {
        $dns = new PhpDnsResolver();
        $method = new \ReflectionMethod(PhpDnsResolver::class, 'fromDnsRecords');
        $ips = $method->invoke($dns, 'one.one.one.one');
        self::assertContainsOnly('string', $ips);
        self::assertSame($ips, array_values(array_unique($ips)));
    }

    public function testFromGethostbynamelReturnsUniqueList(): void
    {
        $dns = new PhpDnsResolver();
        $method = new \ReflectionMethod(PhpDnsResolver::class, 'fromGethostbynamel');
        $list = $method->invoke($dns, 'one.one.one.one');
        self::assertContainsOnly('string', $list);
        self::assertSame($list, array_values(array_unique($list)));
    }
}
