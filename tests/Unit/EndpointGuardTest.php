<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Contract\DnsResolverInterface;
use CloudCastle\Ssh\Exception\SecurityException;
use CloudCastle\Ssh\Security\EndpointGuard;
use CloudCastle\Ssh\Security\PinnedEndpoint;
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
#[CoversClass(EndpointGuard::class)]
#[CoversClass(PinnedEndpoint::class)]
#[CoversClass(ConnectionOptions::class)]
final class EndpointGuardTest extends TestCase
{
    public function testBlocksPrivateIp(): void
    {
        $this->expectException(SecurityException::class);
        (new EndpointGuard())->prepare('127.0.0.1:22', ConnectionOptions::defaults());
    }

    public function testAllowsPrivateWhenDisabled(): void
    {
        $options = ConnectionOptions::defaults()->allowPrivateAddresses();
        $pinned = (new EndpointGuard())->prepare('127.0.0.1:2222', $options);
        self::assertSame(2222, $pinned->port());
        self::assertSame('127.0.0.1', $pinned->host());
        self::assertSame([], $pinned->resolvedIps());
        self::assertNotSame('', $pinned->connectEndpoint());
    }

    public function testBlocksLocalhostName(): void
    {
        $this->expectException(SecurityException::class);
        (new EndpointGuard())->prepare('localhost', ConnectionOptions::defaults());
    }

    public function testBlocksDnsToPrivate(): void
    {
        $dns = new class () implements DnsResolverInterface {
            #[\Override]
            public function resolve(string $host): array
            {
                unset($host);

                return ['10.0.0.1'];
            }
        };
        $this->expectException(SecurityException::class);
        (new EndpointGuard($dns))->prepare('evil.example', ConnectionOptions::defaults());
    }

    public function testPinsPublicDns(): void
    {
        $dns = new class () implements DnsResolverInterface {
            #[\Override]
            public function resolve(string $host): array
            {
                unset($host);

                return ['8.8.8.8'];
            }
        };
        $pinned = (new EndpointGuard($dns))->prepare('dns.google:22', ConnectionOptions::defaults());
        self::assertSame(['8.8.8.8'], $pinned->resolvedIps());
    }

    public function testRejectsEmptyEndpoint(): void
    {
        $this->expectException(SecurityException::class);
        (new EndpointGuard())->prepare('  ', ConnectionOptions::defaults()->allowPrivateAddresses());
    }

    public function testExtractsHostFromUriAndIpv6(): void
    {
        $guard = new EndpointGuard();
        self::assertSame('example.com', $guard->extractHost('ssh://example.com:22'));
        self::assertSame(2200, $guard->extractPort('ssh://example.com:2200'));
        self::assertSame('::1', $guard->extractHost('[::1]:22'));
        self::assertSame(22, $guard->extractPort('[::1]:22'));
        self::assertSame(22, $guard->extractPort('host'));
    }

    public function testControlCharsRejected(): void
    {
        $this->expectException(SecurityException::class);
        (new EndpointGuard())->prepare("host\x00name", ConnectionOptions::defaults()->allowPrivateAddresses());
    }

    public function testLocalhostVariantsBlocked(): void
    {
        $guard = new EndpointGuard();
        $this->expectException(SecurityException::class);
        $guard->resolvePublicIps('0.0.0.0');
    }

    public function testDotLocalhostBlocked(): void
    {
        $guard = new EndpointGuard();
        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Запрещённый адрес (SSRF-guard): app.localhost');
        $guard->resolvePublicIps('app.localhost');
    }

    public function testHostPortLowercaseAndExplicitPort(): void
    {
        $guard = new EndpointGuard();
        self::assertSame('example.com', $guard->extractHost('EXAMPLE.COM:2222'));
        self::assertSame(2222, $guard->extractPort('EXAMPLE.COM:2222'));
        self::assertSame(22, $guard->extractPort('example.com:abc'));
    }

    public function testReplaceHostPreservesSchemePathAndPort(): void
    {
        $guard = new EndpointGuard();
        $method = new \ReflectionMethod(EndpointGuard::class, 'replaceHost');
        $replaced = $method->invoke($guard, 'ssh://Example.COM:2222/extra', '8.8.4.4');
        self::assertSame('ssh://8.8.4.4:2222/extra', $replaced);
        $ipv6 = $method->invoke($guard, '[::1]:2200', '2001:db8::5');
        self::assertSame('[2001:db8::5]:2200', $ipv6);
    }

    public function testUrlHostLowercaseWithoutBrackets(): void
    {
        $guard = new EndpointGuard();
        self::assertSame('example.com', $guard->extractHost('ssh://EXAMPLE.COM:22'));
    }
}
