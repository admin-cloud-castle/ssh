<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Auth\PublicKeySigner;
use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Wire\SshBinary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Жёсткие проверки wire-формата USERAUTH publickey — убивают Concat/ConcatOperandRemoval.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.ShortVariable")
 */
#[CoversClass(PublicKeySigner::class)]
#[CoversClass(SshBinary::class)]
#[CoversClass(AuthCredentials::class)]
final class PublicKeySignerWireTest extends TestCase
{
    /**
     * buildRequest: фиксированный порядок SSH-строк и флаг hasSig=\x01.
     */
    public function testBuildRequestWireOrderAndSignatureBlob(): void
    {
        $pem = $this->rsaPem();
        $signer = new PublicKeySigner();
        $parts = $signer->load(AuthCredentials::withPublicKey('alice', $pem));
        $sessionId = str_repeat("\x11", 32);
        $req = $signer->buildRequest($sessionId, 'alice', $parts);

        self::assertSame(50, ord($req[0]));
        $offset = 1;
        self::assertSame('alice', SshBinary::readString($req, $offset));
        self::assertSame('ssh-connection', SshBinary::readString($req, $offset));
        self::assertSame('publickey', SshBinary::readString($req, $offset));
        self::assertSame("\x01", $req[$offset]);
        ++$offset;
        self::assertSame('rsa-sha2-256', SshBinary::readString($req, $offset));
        $publicBlob = SshBinary::readString($req, $offset);
        self::assertSame($parts['publicBlob'], $publicBlob);
        $signatureBlob = SshBinary::readString($req, $offset);
        self::assertSame($offset, strlen($req));

        $sigOff = 0;
        self::assertSame('rsa-sha2-256', SshBinary::readString($signatureBlob, $sigOff));
        $rawSig = SshBinary::readString($signatureBlob, $sigOff);
        self::assertNotSame('', $rawSig);
        self::assertSame($sigOff, strlen($signatureBlob));

        $blobOff = 0;
        self::assertSame('ssh-rsa', SshBinary::readString($publicBlob, $blobOff));
        $exponent = SshBinary::readMpint($publicBlob, $blobOff);
        $modulus = SshBinary::readMpint($publicBlob, $blobOff);
        self::assertNotSame('', $exponent);
        self::assertNotSame('', $modulus);
        self::assertSame($blobOff, strlen($publicBlob));

        $rsaDetails = openssl_pkey_get_details($parts['key']);
        self::assertIsArray($rsaDetails);
        self::assertIsArray($rsaDetails['rsa'] ?? null);
        self::assertSame($rsaDetails['rsa']['e'], $exponent);
        self::assertSame($rsaDetails['rsa']['n'], $modulus);

        $toSign = $this->canonicalToSign($sessionId, 'alice', $parts['algorithm'], $publicBlob);
        $details = openssl_pkey_get_details($parts['key']);
        self::assertIsArray($details);
        self::assertArrayHasKey('key', $details);
        $verifyKey = $details['key'];
        self::assertTrue(is_string($verifyKey) || $verifyKey instanceof \OpenSSLAsymmetricKey);
        self::assertSame(1, openssl_verify($toSign, $rawSig, $verifyKey, OPENSSL_ALGO_SHA256));
    }

    /**
     * Алгоритмы rsa-sha2-512 / ssh-rsa меняют и поле algorithm, и подпись.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function algorithmProvider(): iterable
    {
        yield 'sha512' => ['rsa-sha2-512'];
        yield 'sha1' => ['ssh-rsa'];
    }

    #[DataProvider('algorithmProvider')]
    public function testBuildRequestHonoursAlgorithmOverride(string $algorithm): void
    {
        $pem = $this->rsaPem();
        $signer = new PublicKeySigner();
        $parts = $signer->load(AuthCredentials::withPublicKey('bob', $pem));
        $parts['algorithm'] = $algorithm;
        $req = $signer->buildRequest(str_repeat('s', 32), 'bob', $parts);
        $offset = 1;
        SshBinary::readString($req, $offset);
        SshBinary::readString($req, $offset);
        SshBinary::readString($req, $offset);
        ++$offset;
        self::assertSame($algorithm, SshBinary::readString($req, $offset));
        SshBinary::readString($req, $offset);
        $signatureBlob = SshBinary::readString($req, $offset);
        $sigOff = 0;
        self::assertSame($algorithm, SshBinary::readString($signatureBlob, $sigOff));

        $publicBlob = $parts['publicBlob'];
        $sessionId = str_repeat('s', 32);
        $toSign = $this->canonicalToSign($sessionId, 'bob', $algorithm, $publicBlob);
        $rawSig = SshBinary::readString($signatureBlob, $sigOff);
        $opensslAlgo = match ($algorithm) {
            'rsa-sha2-512' => OPENSSL_ALGO_SHA512,
            'ssh-rsa' => OPENSSL_ALGO_SHA1,
            default => OPENSSL_ALGO_SHA256,
        };
        $details = openssl_pkey_get_details($parts['key']);
        self::assertIsArray($details);
        self::assertArrayHasKey('key', $details);
        $verifyKey = $details['key'];
        self::assertTrue(is_string($verifyKey) || $verifyKey instanceof \OpenSSLAsymmetricKey);
        self::assertSame(1, openssl_verify($toSign, $rawSig, $verifyKey, $opensslAlgo));
    }

    /**
     * Разные username дают разный request (убивает удаление username из concat).
     */
    public function testUsernameAffectsRequestBytes(): void
    {
        $pem = $this->rsaPem();
        $signer = new PublicKeySigner();
        $parts = $signer->load(AuthCredentials::withPublicKey('x', $pem));
        $requestUserA = $signer->buildRequest(str_repeat('z', 32), 'user-a', $parts);
        $requestUserB = $signer->buildRequest(str_repeat('z', 32), 'user-b', $parts);
        self::assertNotSame($requestUserA, $requestUserB);
        self::assertStringContainsString(SshBinary::sshString('user-a'), $requestUserA);
        self::assertStringContainsString(SshBinary::sshString('user-b'), $requestUserB);
    }

    /**
     * Разный session id меняет подпись при том же username (chr(50) и concat в sign).
     */
    public function testSessionIdAffectsSignatureBlob(): void
    {
        $pem = $this->rsaPem();
        $signer = new PublicKeySigner();
        $parts = $signer->load(AuthCredentials::withPublicKey('u', $pem));
        $reqA = $signer->buildRequest(str_repeat('a', 32), 'u', $parts);
        $reqB = $signer->buildRequest(str_repeat('b', 32), 'u', $parts);
        self::assertNotSame($reqA, $reqB);

        $extractSig = static function (string $req): string {
            $offset = 1;
            SshBinary::readString($req, $offset);
            SshBinary::readString($req, $offset);
            SshBinary::readString($req, $offset);
            ++$offset;
            SshBinary::readString($req, $offset);
            SshBinary::readString($req, $offset);

            return SshBinary::readString($req, $offset);
        };
        self::assertNotSame($extractSig($reqA), $extractSig($reqB));
    }

    /**
     * rsaParts отклоняет битый массив openssl rsa (LogicalAnd на n/e).
     */
    public function testRsaPartsRejectsMissingModulusOrExponent(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $method = new ReflectionMethod(PublicKeySigner::class, 'rsaParts');
        $signer = new PublicKeySigner();

        try {
            $method->invoke($signer, $key, null);
            self::fail('ожидался ConnectionException');
        } catch (ConnectionException $e) {
            self::assertSame('RSA-ключ без модуля/экспоненты', $e->getMessage());
        }

        try {
            $method->invoke($signer, $key, ['n' => 'x']);
            self::fail('ожидался ConnectionException');
        } catch (ConnectionException $e) {
            self::assertSame('RSA-ключ без модуля/экспоненты', $e->getMessage());
        }
    }

    /**
     * requireSignatureBytes отклоняет пустую подпись.
     */
    public function testRequireSignatureBytesRejectsEmpty(): void
    {
        $method = new ReflectionMethod(PublicKeySigner::class, 'requireSignatureBytes');
        $signer = new PublicKeySigner();

        try {
            $method->invoke($signer, '', 'RSA-SHA256');
            self::fail('ожидался ConnectionException');
        } catch (ConnectionException $e) {
            self::assertSame('RSA-SHA256 подпись не удалась', $e->getMessage());
        }
    }

    /**
     * Ed25519: wire-формат и ed25519Secret (если openssl поддерживает).
     */
    public function testEd25519BuildRequestWireWhenAvailable(): void
    {
        if (!defined('OPENSSL_KEYTYPE_ED25519')) {
            self::markTestSkipped('OPENSSL_KEYTYPE_ED25519 недоступен');
        }

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_ED25519]);

        if ($key === false) {
            self::markTestSkipped('openssl не создал Ed25519 ключ');
        }

        $pem = '';
        self::assertTrue(openssl_pkey_export($key, $pem));
        self::assertIsString($pem);
        $signer = new PublicKeySigner();
        $parts = $signer->load(AuthCredentials::withPublicKey('ed', $pem));
        self::assertSame('ssh-ed25519', $parts['algorithm']);
        self::assertIsString($parts['ed25519Secret']);
        self::assertSame(64, strlen($parts['ed25519Secret']));

        $sessionId = str_repeat("\x22", 32);
        $req = $signer->buildRequest($sessionId, 'ed', $parts);
        $offset = 1;
        self::assertSame('ed', SshBinary::readString($req, $offset));
        self::assertSame('ssh-connection', SshBinary::readString($req, $offset));
        self::assertSame('publickey', SshBinary::readString($req, $offset));
        self::assertSame("\x01", $req[$offset]);
        ++$offset;
        self::assertSame('ssh-ed25519', SshBinary::readString($req, $offset));
        $publicBlob = SshBinary::readString($req, $offset);
        $signatureBlob = SshBinary::readString($req, $offset);
        self::assertSame($offset, strlen($req));

        $blobOff = 0;
        self::assertSame('ssh-ed25519', SshBinary::readString($publicBlob, $blobOff));
        $pubFromBlob = SshBinary::readString($publicBlob, $blobOff);
        self::assertSame(32, strlen($pubFromBlob));

        $sigOff = 0;
        self::assertSame('ssh-ed25519', SshBinary::readString($signatureBlob, $sigOff));
        $rawSig = SshBinary::readString($signatureBlob, $sigOff);
        self::assertSame(64, strlen($rawSig));

        if (function_exists('sodium_crypto_sign_verify_detached')) {
            $toSign = $this->canonicalToSign($sessionId, 'ed', 'ssh-ed25519', $publicBlob);
            self::assertTrue(sodium_crypto_sign_verify_detached($rawSig, $toSign, $pubFromBlob));
        }
    }

    /**
     * ssh-ed25519 + sodium secret: убивает MatchArmRemoval (не RSA-SHA1 fallback).
     */
    public function testSodiumEd25519PartsSignWithVerify(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('sodium_crypto_sign_keypair недоступен');
        }

        $keyPair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keyPair);
        $pub = sodium_crypto_sign_publickey($keyPair);
        self::assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen($secretKey));
        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($rsa);
        $publicBlob = SshBinary::sshString('ssh-ed25519') . SshBinary::sshString($pub);
        $parts = [
            'algorithm' => 'ssh-ed25519',
            'publicBlob' => $publicBlob,
            'key' => $rsa,
            'ed25519Secret' => $secretKey,
        ];
        $sessionId = str_repeat("\x33", 32);
        $signer = new PublicKeySigner();
        $req = $signer->buildRequest($sessionId, 'sodium-user', $parts);
        $offset = 1;
        self::assertSame('sodium-user', SshBinary::readString($req, $offset));
        SshBinary::readString($req, $offset);
        SshBinary::readString($req, $offset);
        ++$offset;
        self::assertSame('ssh-ed25519', SshBinary::readString($req, $offset));
        SshBinary::readString($req, $offset);
        $signatureBlob = SshBinary::readString($req, $offset);
        $sigOff = 0;
        self::assertSame('ssh-ed25519', SshBinary::readString($signatureBlob, $sigOff));
        $rawSig = SshBinary::readString($signatureBlob, $sigOff);
        self::assertSame(64, strlen($rawSig));

        $toSign = $this->canonicalToSign($sessionId, 'sodium-user', 'ssh-ed25519', $publicBlob);
        self::assertTrue(sodium_crypto_sign_verify_detached($rawSig, $toSign, $pub));
    }

    /**
     * Байты, подписываемые USERAUTH publickey (RFC 4252).
     *
     * @return non-empty-string
     */
    private function canonicalToSign(
        string $sessionId,
        string $username,
        string $algorithm,
        string $publicBlob,
    ): string {
        return SshBinary::sshString($sessionId)
            . chr(50)
            . SshBinary::sshString($username)
            . SshBinary::sshString('ssh-connection')
            . SshBinary::sshString('publickey')
            . "\x01"
            . SshBinary::sshString($algorithm)
            . SshBinary::sshString($publicBlob);
    }

    private function rsaPem(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $pem = '';
        self::assertTrue(openssl_pkey_export($key, $pem));
        self::assertIsString($pem);
        self::assertNotSame('', $pem);

        return $pem;
    }
}
