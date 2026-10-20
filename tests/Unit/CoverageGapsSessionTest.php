<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Auth\PublicKeySigner;
use CloudCastle\Ssh\Client\SessionFactory;
use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Contract\ConnectionOptions;
use CloudCastle\Ssh\Contract\SessionFactoryInterface;
use CloudCastle\Ssh\Exception\AuthenticationException;
use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Pool\SessionPool;
use CloudCastle\Ssh\Process\ProcessPool;
use CloudCastle\Ssh\Security\EndpointGuard;
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
 * Вторая часть coverage-gaps (сессии / wire / factory) — лимит NLOC Codacy.
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
#[CoversClass(SessionFactory::class)]
#[CoversClass(NativeSession::class)]
#[CoversClass(SshWire::class)]
#[CoversClass(SshBinary::class)]
#[CoversClass(EndpointGuard::class)]
#[CoversClass(PhpDnsResolver::class)]
#[CoversClass(PinnedEndpoint::class)]
#[CoversClass(ConnectionOptions::class)]
#[CoversClass(AuthCredentials::class)]
#[CoversClass(PublicKeySigner::class)]
#[CoversClass(SessionPool::class)]
#[CoversClass(ProcessPool::class)]
#[UsesClass(MockSshPeer::class)]
final class CoverageGapsSessionTest extends TestCase
{
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
            $session = new NativeSession($stream, $empty);
            self::assertFalse($session->isConnected());
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
            self::unlinkTempFile($path);
            MockSshPeer::stop($process);
        }
    }

    public function testNativeSessionAuthRejected(): void
    {
        putenv('CC_SSH_REJECT_AUTH=1');
        [$stream, $process] = MockSshPeer::start();

        try {
            $this->expectException(AuthenticationException::class);
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            self::assertFalse($session->isConnected());
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
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            self::assertFalse($session->isConnected());
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

    public function testNativeSessionServiceAcceptMismatch(): void
    {
        putenv('CC_SSH_SERVICE_MISMATCH=1');
        [$stream, $process] = MockSshPeer::start();

        try {
            $this->expectException(ConnectionException::class);
            $this->expectExceptionMessage('SSH service accept mismatch');
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            self::fail('ожидалось ConnectionException, получена сессия ' . $session::class);
        } finally {
            putenv('CC_SSH_SERVICE_MISMATCH');
            MockSshPeer::stop($process);
        }
    }

    public function testNativeSessionBadDhParameter(): void
    {
        putenv('CC_SSH_BAD_DH=1');
        [$stream, $process] = MockSshPeer::start();

        try {
            $this->expectException(ConnectionException::class);
            $this->expectExceptionMessage('Некорректный DH параметр f');
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            self::fail('ожидалось ConnectionException, получена сессия ' . $session::class);
        } finally {
            putenv('CC_SSH_BAD_DH');
            MockSshPeer::stop($process);
        }
    }

    public function testNativeSessionDeriveKeyExtendsBeyondOneHash(): void
    {
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            $derive = new ReflectionMethod(NativeSession::class, 'deriveKey');
            $k = SshBinary::mpint("\x01\x02");
            $h = str_repeat('H', 32);
            $key = $derive->invoke($session, $k, $h, 'A', 33);
            self::assertSame(33, strlen($key));
            $session->disconnect();
        } finally {
            MockSshPeer::stop($process);
        }
    }

    public function testNativeSessionComputeExchangeHashOrder(): void
    {
        [$stream, $process] = MockSshPeer::start();

        try {
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            $method = new ReflectionMethod(NativeSession::class, 'computeExchangeHash');
            $clientKex = chr(20) . str_repeat('a', 40);
            $serverKex = chr(20) . str_repeat('b', 40);
            $hostKey = SshBinary::sshString('host');
            $eBytes = "\x03";
            $fBytes = "\x04";
            $k = SshBinary::mpint("\x05");
            $hash = $method->invoke($session, $clientKex, $serverKex, $hostKey, $eBytes, $fBytes, $k);
            self::assertSame(32, strlen($hash));
            $serverId = new \ReflectionProperty(NativeSession::class, 'serverId');
            $sid = $serverId->getValue($session);
            self::assertIsString($sid);
            $clientId = (new \ReflectionClass(NativeSession::class))->getConstant('CLIENT_ID');
            self::assertIsString($clientId);
            $expected = hash(
                'sha256',
                SshBinary::sshString($clientId)
                . SshBinary::sshString($sid)
                . SshBinary::sshString($clientKex)
                . SshBinary::sshString($serverKex)
                . SshBinary::sshString($hostKey)
                . SshBinary::mpint($eBytes)
                . SshBinary::mpint($fBytes)
                . $k,
                true,
            );
            self::assertSame($expected, $hash);
            $session->disconnect();
        } finally {
            MockSshPeer::stop($process);
        }
    }

    public function testNativeSessionAuthFailureMessagePrefix(): void
    {
        putenv('CC_SSH_REJECT_AUTH=1');
        [$stream, $process] = MockSshPeer::start();

        try {
            $this->expectException(AuthenticationException::class);
            $this->expectExceptionMessage('SSH-аутентификация отклонена');
            $session = new NativeSession($stream, AuthCredentials::withPassword('user', 'secret'));
            self::fail('ожидалось AuthenticationException, получена сессия ' . $session::class);
        } finally {
            putenv('CC_SSH_REJECT_AUTH');
            MockSshPeer::stop($process);
        }
    }

    public function testNativeSessionMissingCredentialsMessage(): void
    {
        [$stream, $process] = MockSshPeer::start();
        $ref = new \ReflectionClass(AuthCredentials::class);
        $ctor = $ref->getConstructor();
        self::assertNotNull($ctor);
        $empty = $ref->newInstanceWithoutConstructor();
        $ctor->invoke($empty, 'u', null, null, null);

        try {
            $this->expectException(AuthenticationException::class);
            $this->expectExceptionMessage(
                'Для SSH нужны AuthCredentials::withPassword или AuthCredentials::withPublicKey',
            );
            $session = new NativeSession($stream, $empty);
            self::fail('ожидалось AuthenticationException, получена сессия ' . $session::class);
        } finally {
            MockSshPeer::stop($process);
        }
    }

    /**
     * Удаляет файл только внутри sys_get_temp_dir().
     */
    private static function unlinkTempFile(string $path): void
    {
        $tempRoot = realpath(sys_get_temp_dir());
        $real = realpath($path);

        if ($tempRoot === false || $real === false || !str_starts_with($real, $tempRoot)) {
            return;
        }

        // Путь уже проверен realpath + префикс temp dir.
        unlink($real); // nosemgrep: php.lang.security.unlink-use.unlink-use
    }
}
