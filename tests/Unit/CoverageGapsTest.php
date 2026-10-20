<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Async\FiberAsyncRuntime;
use CloudCastle\Ssh\Async\NativeStreamSelector;
use CloudCastle\Ssh\Auth\PublicKeySigner;
use CloudCastle\Ssh\Client\SessionFactory;
use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Contract\DnsResolverInterface;
use CloudCastle\Ssh\Contract\SessionFactoryInterface;
use CloudCastle\Ssh\Contract\SessionInterface;
use CloudCastle\Ssh\Exception\AuthenticationException;
use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Exception\SecurityException;
use CloudCastle\Ssh\Exception\TimeoutException;
use CloudCastle\Ssh\Forward\LocalPortForward;
use CloudCastle\Ssh\Pool\SessionPool;
use CloudCastle\Ssh\Process\ProcessPool;
use CloudCastle\Ssh\Security\EndpointGuard;
use CloudCastle\Ssh\Security\HostKeyVerifier;
use CloudCastle\Ssh\Security\PhpDnsResolver;
use CloudCastle\Ssh\Security\PinnedEndpoint;
use CloudCastle\Ssh\Session\NativeSession;
use CloudCastle\Ssh\Tests\Support\MockSshPeer;
use CloudCastle\Ssh\Wire\SshBinary;
use CloudCastle\Ssh\Wire\SshWire;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Добивает покрытие веток до порога 99.8%.
 *
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
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.EmptyCatchBlock")
 */
#[CoversClass(FiberAsyncRuntime::class)]
#[CoversClass(NativeStreamSelector::class)]
#[CoversClass(PublicKeySigner::class)]
#[CoversClass(SessionFactory::class)]
#[CoversClass(EndpointGuard::class)]
#[CoversClass(PhpDnsResolver::class)]
#[CoversClass(ProcessPool::class)]
#[CoversClass(SessionPool::class)]
#[CoversClass(LocalPortForward::class)]
#[CoversClass(SshBinary::class)]
#[CoversClass(SshWire::class)]
#[CoversClass(NativeSession::class)]
#[CoversClass(AuthCredentials::class)]
#[CoversClass(ConnectionOptions::class)]
#[CoversClass(PinnedEndpoint::class)]
#[CoversClass(HostKeyVerifier::class)]
#[UsesClass(PinnedEndpoint::class)]
#[UsesClass(HostKeyVerifier::class)]
#[UsesClass(MockSshPeer::class)]
final class CoverageGapsTest extends TestCase
{
    public function testNativeStreamSelectorSelect(): void
    {
        $selector = new NativeStreamSelector();
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        $read = [$server];
        $write = null;
        $except = null;
        $result = $selector->select($read, $write, $except, 0, 1000);
        self::assertIsInt($result);
        fclose($server);
    }

    public function testFiberAwaitOutsideFiberAndTimeout(): void
    {
        $runtime = new FiberAsyncRuntime();
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($server);
        $name = stream_socket_get_name($server, false);
        self::assertIsString($name);
        $colonPos = strrpos($name, ':');
        self::assertNotFalse($colonPos);
        $port = (int) substr($name, $colonPos + 1);
        $client = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 1.0);
        self::assertNotFalse($client);
        stream_set_blocking($client, false);
        $runtime->awaitWritable($client, 1.0);

        $this->expectException(TimeoutException::class);

        try {
            $runtime->awaitReadable($client, 0.05);
        } finally {
            fclose($client);
            fclose($server);
        }
    }

    public function testFiberRunNonWaitResumeAndBadOp(): void
    {
        $runtime = new FiberAsyncRuntime();
        $result = $runtime->run(static function () use ($runtime): int {
            \Fiber::suspend('not-wait');
            $stream = fopen('php://memory', 'r+b');
            self::assertNotFalse($stream);
            $runtime->awaitReadable($stream, 0.1);
            fclose($stream);

            return 7;
        });
        self::assertSame(7, $result);

        $method = new ReflectionMethod(FiberAsyncRuntime::class, 'bucketsForOp');
        $stream = fopen('php://memory', 'r+b');
        self::assertNotFalse($stream);

        try {
            $this->expectException(TimeoutException::class);
            $method->invoke($runtime, $stream, 'other');
        } finally {
            fclose($stream);
        }
    }

    public function testFiberSplitTimeoutEdges(): void
    {
        $runtime = new FiberAsyncRuntime();
        $method = new ReflectionMethod(FiberAsyncRuntime::class, 'splitTimeout');
        self::assertSame([0, 0], $method->invoke($runtime, 0.0));
        self::assertSame([1, 0], $method->invoke($runtime, 0.9999999));
        $parts = $method->invoke($runtime, 1.5);
        self::assertIsArray($parts);
        self::assertCount(2, $parts);
        self::assertSame(1, $parts[0]);
        self::assertGreaterThan(0, $parts[1]);
    }

    public function testProcessPoolSequentialAndHelpers(): void
    {
        $pool = new ProcessPool(2, true);
        $out = iterator_to_array($pool->map([1, 2], static fn (int $v): int => $v + 1), false);
        self::assertSame([2, 3], $out);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$left, $right] = $pair;
        $pool->writeWorkerResult($left, ['ok' => 1]);
        fclose($left);
        $body = $pool->readWorkerPayload($right);
        fclose($right);
        self::assertSame(['ok' => 1], unserialize($body, ['allowed_classes' => false]));

        $forked = new ProcessPool(2, false);
        $forkOut = iterator_to_array($forked->map([4, 5], static fn (int $v): int => $v * 3), false);
        sort($forkOut);
        self::assertSame([12, 15], $forkOut);
    }

    public function testProcessPoolReadTruncatedPayload(): void
    {
        $pool = new ProcessPool(1, true);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$left, $right] = $pair;
        fwrite($left, 'ab');
        fclose($left);
        self::assertSame('', $pool->readWorkerPayload($right));
        fclose($right);
    }

    public function testLocalPortForwardRawResponse(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('exec')->willReturn('not-base64!!!');
        $fwd = new LocalPortForward($session);
        self::assertSame('not-base64!!!', $fwd->request('h', 1, 'x'));
    }

    public function testSessionPoolDisconnectsStaleIdle(): void
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
        $got = $pool->acquire('h', AuthCredentials::withPassword('u', 'p'));
        self::assertSame($alive, $got);
    }

    public function testEndpointGuardUrlAndIpv6Branches(): void
    {
        $dns = new class () implements DnsResolverInterface {
            #[\Override]
            public function resolve(string $host): array
            {
                unset($host);

                return ['1.1.1.1'];
            }
        };
        $guard = new EndpointGuard($dns);
        $pinned = $guard->prepare('ssh://example.com:2222/path', ConnectionOptions::defaults());
        self::assertSame(2222, $pinned->port());
        self::assertSame(['1.1.1.1'], $pinned->resolvedIps());
        self::assertStringContainsString('1.1.1.1', $pinned->connectEndpoint());

        self::assertNull($guard->extractHost('ssh://'));
        self::assertNull($guard->extractHost('['));
        self::assertSame(22, $guard->extractPort('ssh://example.com'));
        self::assertSame(22, $guard->extractPort('[::1]'));
        self::assertSame(443, $guard->extractPort('[::1]:443'));

        $emptyHost = $guard->prepare('ssh://', ConnectionOptions::defaults());
        self::assertSame([], $emptyHost->resolvedIps());

        $publicIp = $guard->resolvePublicIps('8.8.8.8');
        self::assertSame(['8.8.8.8'], $publicIp);
    }

    public function testEndpointGuardUnresolvedHost(): void
    {
        $dns = new class () implements DnsResolverInterface {
            #[\Override]
            public function resolve(string $host): array
            {
                unset($host);

                return [];
            }
        };
        $this->expectException(SecurityException::class);
        (new EndpointGuard($dns))->resolvePublicIps('missing.example');
    }

    public function testEndpointGuardReplaceHostFallbacks(): void
    {
        $guard = new EndpointGuard();
        $method = new ReflectionMethod(EndpointGuard::class, 'replaceHost');
        self::assertSame('8.8.8.8', $method->invoke($guard, 'host', '8.8.8.8'));
        self::assertSame('[::1]:22', $method->invoke($guard, '[fe80::1]:22', '::1'));
        self::assertSame('[::1]', $method->invoke($guard, '[broken', '::1'));
        self::assertSame('9.9.9.9:22', $method->invoke($guard, 'a.b:22', '9.9.9.9'));
        $replaced = $method->invoke($guard, 'tcp://host:22/x', '1.2.3.4');
        self::assertIsString($replaced);
        self::assertStringStartsWith('tcp://', $replaced);
    }

    public function testPhpDnsResolverHostnameAndIpv6Record(): void
    {
        $dns = new PhpDnsResolver();
        self::assertSame(['::1'], $dns->resolve('::1'));
        $ips = $dns->resolve('localhost');
        self::assertContainsOnly('string', $ips);
        $public = $dns->resolve('one.one.one.one');
        self::assertNotSame([], $public);
        self::assertContainsOnly('string', $public);
    }

    public function testPublicKeyFromFileAndAlgorithms(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $path = tempnam(sys_get_temp_dir(), 'ccsshkey');
        self::assertNotFalse($path);
        self::assertTrue(openssl_pkey_export_to_file($key, $path));
        $signer = new PublicKeySigner();
        $parts = $signer->load(AuthCredentials::withPublicKey('u', $path));
        unlink($path);
        self::assertSame('rsa-sha2-256', $parts['algorithm']);

        $parts['algorithm'] = 'rsa-sha2-512';
        $req512 = $signer->buildRequest(str_repeat('b', 32), 'u', $parts);
        self::assertSame(50, ord($req512[0]));

        $parts['algorithm'] = 'ssh-rsa';
        $reqSha1 = $signer->buildRequest(str_repeat('c', 32), 'u', $parts);
        self::assertSame(50, ord($reqSha1[0]));

        $this->expectException(ConnectionException::class);
        $signer->load(AuthCredentials::withPublicKey('u', ''));
    }

    public function testPublicKeyEd25519WhenAvailable(): void
    {
        if (!defined('OPENSSL_KEYTYPE_ED25519')) {
            self::markTestSkipped('OPENSSL_KEYTYPE_ED25519 недоступен');
        }

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_ED25519]);

        if ($key === false) {
            self::markTestSkipped('openssl не создал Ed25519 ключ');
        }

        $path = tempnam(sys_get_temp_dir(), 'ccsshed');
        self::assertNotFalse($path);
        self::assertTrue(openssl_pkey_export_to_file($key, $path));
        $signer = new PublicKeySigner();
        $parts = $signer->load(AuthCredentials::withPublicKey('ed', $path));
        unlink($path);
        self::assertSame('ssh-ed25519', $parts['algorithm']);
        $req = $signer->buildRequest(str_repeat('d', 32), 'ed', $parts);
        self::assertSame(50, ord($req[0]));
    }

    public function testPublicKeyUnsupportedType(): void
    {
        $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        if ($ec === false) {
            self::markTestSkipped('openssl не создал EC ключ');
        }

        $path = tempnam(sys_get_temp_dir(), 'ccsshec');
        self::assertNotFalse($path);
        self::assertTrue(openssl_pkey_export_to_file($ec, $path));
        $signer = new PublicKeySigner();
        $this->expectException(ConnectionException::class);

        try {
            $signer->load(AuthCredentials::withPublicKey('u', $path));
        } finally {
            unlink($path);
        }
    }

    public function testSessionFactoryUrlEndpoint(): void
    {
        [$port, $process] = MockSshPeer::listen();

        try {
            $factory = new SessionFactory();
            $session = $factory->connect(
                'tcp://127.0.0.1:' . $port,
                AuthCredentials::withPassword('u', 'p'),
                ConnectionOptions::defaults()->allowPrivateAddresses(),
            );
            self::assertTrue($session->isConnected());
            $session->disconnect();
        } finally {
            MockSshPeer::stop($process);
        }
    }

    public function testNativeSessionAuthFailureAndEmptySubsystem(): void
    {
        [$stream, $process] = MockSshPeer::start();
        $session = null;

        try {
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            $this->expectException(ConnectionException::class);
            $session->openSubsystem('');
        } finally {
            if ($session instanceof NativeSession) {
                $session->disconnect();
            }

            MockSshPeer::stop($process);
        }
    }

    public function testNativeSessionMissingCredentials(): void
    {
        [$stream, $process] = MockSshPeer::start();

        try {
            $ref = new \ReflectionClass(AuthCredentials::class);
            $ctor = $ref->getConstructor();
            self::assertNotNull($ctor);
            $empty = $ref->newInstanceWithoutConstructor();
            $ctor->invoke($empty, 'u', null, null, null);
            $this->expectException(AuthenticationException::class);
            new NativeSession($stream, $empty);
        } finally {
            MockSshPeer::stop($process);
        }
    }

    public function testSshBinaryShortBuffer(): void
    {
        $offset = 0;
        self::assertSame(0, SshBinary::readUint32('ab', $offset));
        self::assertSame(2, $offset);
        $offset = 0;
        self::assertSame('', SshBinary::readMpint(SshBinary::sshString(''), $offset));
    }

    public function testConnectionOptionsAccessors(): void
    {
        $options = new ConnectionOptions(5.0, 6.0, false, ['fp'], true);
        self::assertSame(5.0, $options->connectTimeout());
        self::assertSame(6.0, $options->ioTimeout());
        self::assertFalse($options->blockPrivateAddresses());
        self::assertSame(['fp'], $options->trustedHostKeys());
        self::assertTrue($options->strictHostKey());
        $auth = AuthCredentials::withPublicKey('u', 'k', 'pass');
        self::assertSame('pass', $auth->passphrase());
    }

    public function testSessionFactoryBuildTargetBranches(): void
    {
        $factory = new SessionFactory();
        $method = new ReflectionMethod(SessionFactory::class, 'buildTarget');

        $emptyHost = new PinnedEndpoint('x', '', [], 0);
        $built = $method->invoke($factory, $emptyHost, 'fallback.example');
        self::assertIsArray($built);
        self::assertSame('fallback.example', $built[0]);
        self::assertSame(22, $built[1]);
        self::assertIsString($built[2]);
        self::assertStringContainsString('fallback.example', $built[2]);

        $withIps = new PinnedEndpoint(
            '1.1.1.1:22',
            'dns.example',
            ['1.1.1.1'],
            22,
        );
        $ipBuilt = $method->invoke($factory, $withIps, 'dns.example:22');
        self::assertIsArray($ipBuilt);
        self::assertSame('tcp://1.1.1.1:22', $ipBuilt[2]);

        $url = new PinnedEndpoint('tcp://[::1]:2200', '::1', [], 2200);
        $urlBuilt = $method->invoke($factory, $url, '::1');
        self::assertIsArray($urlBuilt);
        self::assertSame('tcp://[::1]:2200', $urlBuilt[2]);

        $urlMethod = new ReflectionMethod(SessionFactory::class, 'targetFromUrl');
        $fromUrl = $urlMethod->invoke($factory, 'not-a-url', 'h', 9);
        self::assertIsArray($fromUrl);
        self::assertSame('h', $fromUrl[0]);
        self::assertSame(9, $fromUrl[1]);
        self::assertSame('tcp://h:9', $fromUrl[2]);

        $tcpMethod = new ReflectionMethod(SessionFactory::class, 'tcpTarget');
        self::assertSame('tcp://[::1]:22', $tcpMethod->invoke($factory, '[::1]', 22));
    }

    public function testPhpDnsResolverPrivateHelpers(): void
    {
        $dns = new PhpDnsResolver();
        $row = new ReflectionMethod(PhpDnsResolver::class, 'ipFromDnsRow');
        self::assertSame('1.2.3.4', $row->invoke($dns, ['type' => 'A', 'ip' => '1.2.3.4']));
        self::assertSame('::1', $row->invoke($dns, ['type' => 'AAAA', 'ipv6' => '::1']));
        self::assertNull($row->invoke($dns, ['type' => 'TXT']));

        $fromDns = new ReflectionMethod(PhpDnsResolver::class, 'fromDnsRecords');
        $ips = $fromDns->invoke($dns, 'localhost');
        self::assertIsArray($ips);
        self::assertContainsOnly('string', $ips);

        $fallback = new ReflectionMethod(PhpDnsResolver::class, 'fromGethostbynamel');
        $list = $fallback->invoke($dns, 'localhost');
        self::assertIsArray($list);
        self::assertContainsOnly('string', $list);
    }

    public function testEndpointGuardEmptyHostBlockPrivate(): void
    {
        $guard = new EndpointGuard();
        $pinned = $guard->prepare('ssh://', ConnectionOptions::defaults());
        self::assertSame([], $pinned->resolvedIps());
        self::assertSame('ssh://', $pinned->connectEndpoint());
        self::assertSame(22, $pinned->port());
    }

    public function testEndpointGuardBracketHostWithoutPort(): void
    {
        $guard = new EndpointGuard();
        self::assertSame('::1', $guard->extractHost('[::1]'));
        self::assertSame(22, $guard->extractPort('[::1]'));
        $method = new ReflectionMethod(EndpointGuard::class, 'hostFromUrl');
        self::assertSame('::1', $method->invoke($guard, 'ssh://[::1]:22'));
    }

    public function testNativeSessionPublicKeyAuth(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $path = tempnam(sys_get_temp_dir(), 'ccsshpk');
        self::assertNotFalse($path);
        self::assertTrue(openssl_pkey_export_to_file($key, $path));
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream, AuthCredentials::withPublicKey('user', $path));
            self::assertTrue($session->isConnected());
            self::assertStringContainsString('mock:', $session->exec('true'));
            $session->disconnect();
        } finally {
            unlink($path);
            MockSshPeer::stop($process);
        }
    }

    public function testNativeSessionAuthRejected(): void
    {
        putenv('CC_SSH_REJECT_AUTH=1');
        [$stream, $process] = MockSshPeer::start();

        try {
            $this->expectException(AuthenticationException::class);
            new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
        } finally {
            putenv('CC_SSH_REJECT_AUTH');
            MockSshPeer::stop($process);
        }
    }

    public function testNativeSessionAuthOddType(): void
    {
        putenv('CC_SSH_AUTH_ODD=1');
        [$stream, $process] = MockSshPeer::start();

        try {
            $this->expectException(AuthenticationException::class);
            new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
        } finally {
            putenv('CC_SSH_AUTH_ODD');
            MockSshPeer::stop($process);
        }
    }

    public function testSshWireClosedStream(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$left, $right] = $pair;
        $wire = new SshWire($left);
        fclose($left);
        $this->expectException(ConnectionException::class);
        $wire->send('x');
        fclose($right);
    }

    public function testProcessPoolTruncatedBodyBreak(): void
    {
        $pool = new ProcessPool(1, true);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        [$left, $right] = $pair;
        fwrite($left, pack('N', 10) . 'abc');
        fclose($left);
        self::assertSame('abc', $pool->readWorkerPayload($right));
        fclose($right);
    }

    public function testNativeSessionWriteAfterDisconnectAndReadEmpty(): void
    {
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            $session->write('abcdef');
            self::assertSame('ec', $session->read(2));
            self::assertSame('ho:abcdef', $session->read(32));
            $session->openSubsystem('sftp');
            $session->openSubsystem('sftp');
            $openChannel = new ReflectionMethod(NativeSession::class, 'openSessionChannel');
            $openChannel->invoke($session);
            $session->disconnect();
            $this->expectException(ConnectionException::class);
            $session->exec('x');
        } finally {
            MockSshPeer::stop($process);
        }
    }

    public function testNativeSessionMaxPerKeyZeroDefaults(): void
    {
        $pool = new SessionPool($this->createMock(SessionFactoryInterface::class), 0);
        self::assertSame(0, $pool->size());
    }

    public function testGmpToBytesOddHexLength(): void
    {
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            $method = new ReflectionMethod(NativeSession::class, 'gmpToBytes');
            $bytes = $method->invoke($session, \gmp_init(1));
            self::assertIsString($bytes);
            self::assertNotSame('', $bytes);
            $session->disconnect();
        } finally {
            MockSshPeer::stop($process);
        }
    }
}
