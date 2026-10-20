<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Auth\PublicKeySigner;
use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Wire\SshBinary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublicKeySigner::class)]
#[CoversClass(SshBinary::class)]
#[CoversClass(AuthCredentials::class)]
final class PublicKeySignerTest extends TestCase
{
    public function testLoadAndBuildRsaRequest(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        $auth = AuthCredentials::withPublicKey('alice', $pem);
        $signer = new PublicKeySigner();
        $parts = $signer->load($auth);
        self::assertSame('rsa-sha2-256', $parts['algorithm']);
        $req = $signer->buildRequest(str_repeat('a', 32), 'alice', $parts);
        self::assertSame(50, ord($req[0]));
    }

    public function testInvalidKeyThrows(): void
    {
        $signer = new PublicKeySigner();
        $this->expectException(ConnectionException::class);
        $signer->load(AuthCredentials::withPublicKey('u', 'not-a-key'));
    }
}