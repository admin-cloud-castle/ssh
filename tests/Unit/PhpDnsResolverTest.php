<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Security\PhpDnsResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpDnsResolver::class)]
final class PhpDnsResolverTest extends TestCase
{
    public function testResolveLocalOrEmpty(): void
    {
        $dns = new PhpDnsResolver();
        $ips = $dns->resolve('localhost');
        self::assertIsArray($ips);
    }
}