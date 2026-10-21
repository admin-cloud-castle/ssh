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
        self::assertNotSame(['localhost'], $local);
    }

    public function testResolveHostnamePrefersDnsRecordsOverGethost(): void
    {
        $dns = new PhpDnsResolver();
        $fromDns = (new \ReflectionMethod(PhpDnsResolver::class, 'fromDnsRecords'))
            ->invoke($dns, 'one.one.one.one');
        $fromGethost = (new \ReflectionMethod(PhpDnsResolver::class, 'fromGethostbynamel'))
            ->invoke($dns, 'one.one.one.one');
        self::assertIsArray($fromDns);
        self::assertIsArray($fromGethost);
        $resolved = $dns->resolve('one.one.one.one');
        self::assertNotSame(['one.one.one.one'], $resolved);

        if ($fromDns !== []) {
            sort($fromDns);
            $sortedResolved = $resolved;
            sort($sortedResolved);
            self::assertSame($fromDns, $sortedResolved);

            return;
        }

        $sortedGethost = $fromGethost;
        sort($sortedGethost);
        $sortedResolved = $resolved;
        sort($sortedResolved);
        self::assertSame($sortedGethost, $sortedResolved);
    }

    public function testUniqueIpsRemovesDuplicatesAndReindexes(): void
    {
        $dns = new PhpDnsResolver();
        $method = new \ReflectionMethod(PhpDnsResolver::class, 'uniqueIps');
        $out = $method->invoke($dns, ['1.1.1.1', '1.1.1.1', '1.0.0.1']);
        self::assertSame([0 => '1.1.1.1', 1 => '1.0.0.1'], $out);
        self::assertSame(['8.8.8.8'], $method->invoke($dns, ['8.8.8.8']));
    }

    public function testFromDnsRecordsDedupesViaReflection(): void
    {
        $dns = new PhpDnsResolver();
        $method = new \ReflectionMethod(PhpDnsResolver::class, 'fromDnsRecords');
        $ips = $method->invoke($dns, 'one.one.one.one');
        self::assertIsArray($ips);
        self::assertNotEmpty($ips);
        $stringIps = [];

        foreach ($ips as $ip) {
            self::assertIsString($ip);
            $stringIps[] = $ip;
        }

        self::assertSame($stringIps, array_values(array_unique($stringIps)));
    }

    public function testDnsHelpersReturnEmptyWhenLookupFails(): void
    {
        $dns = new PhpDnsResolver();
        $fromDns = new \ReflectionMethod(PhpDnsResolver::class, 'fromDnsRecords');
        $fromGethost = new \ReflectionMethod(PhpDnsResolver::class, 'fromGethostbynamel');
        self::assertSame([], $fromDns->invoke($dns, '..'));
        self::assertSame([], $fromGethost->invoke($dns, '..'));
    }

    public function testFromGethostbynamelReturnsUniqueList(): void
    {
        $dns = new PhpDnsResolver();
        $method = new \ReflectionMethod(PhpDnsResolver::class, 'fromGethostbynamel');
        $list = $method->invoke($dns, 'one.one.one.one');
        self::assertIsArray($list);
        $stringList = [];

        foreach ($list as $ip) {
            self::assertIsString($ip);
            $stringList[] = $ip;
        }

        self::assertSame($stringList, array_values(array_unique($stringList)));
    }
}
