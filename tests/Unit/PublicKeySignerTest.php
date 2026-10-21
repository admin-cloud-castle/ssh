<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Auth\PublicKeySigner;
use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Tests\Support\TempPath;
use CloudCastle\Ssh\Wire\SshBinary;
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
#[CoversClass(PublicKeySigner::class)]
#[CoversClass(SshBinary::class)]
#[CoversClass(AuthCredentials::class)]
final class PublicKeySignerTest extends TestCase
{
    public function testLoadAndBuildRsaRequest(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $path = tempnam(sys_get_temp_dir(), 'ccsshkey');
        self::assertNotFalse($path);
        self::assertTrue(openssl_pkey_export_to_file($key, $path));
        $pem = file_get_contents($path);
        $this->unlinkTempFile($path);
        self::assertNotFalse($pem);
        self::assertNotSame('', $pem);

        $auth = AuthCredentials::withPublicKey('alice', $pem);
        $signer = new PublicKeySigner();
        $parts = $signer->load($auth);
        self::assertSame('rsa-sha2-256', $parts['algorithm']);
        $sessionId = str_repeat('a', 32);
        $req = $signer->buildRequest($sessionId, 'alice', $parts);
        self::assertSame(50, ord($req[0]));
        $offset = 1;
        self::assertSame('alice', SshBinary::readString($req, $offset));
        self::assertSame('ssh-connection', SshBinary::readString($req, $offset));
        self::assertSame('publickey', SshBinary::readString($req, $offset));
        self::assertSame("\x01", $req[$offset]);
    }

    public function testInvalidKeyThrows(): void
    {
        $signer = new PublicKeySigner();
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessageMatches('/^Не удалось загрузить SSH private key:/');
        $signer->load(AuthCredentials::withPublicKey('u', 'not-a-key'));
    }

    /**
     * Удаляет файл только внутри sys_get_temp_dir().
     */
    private function unlinkTempFile(string $path): void
    {
        TempPath::unlinkIfTemp($path);
    }
}
