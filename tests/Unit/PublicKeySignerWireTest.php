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
        self::assertSame(strlen($req), $offset);

        $sigOff = 0;
        self::assertSame('rsa-sha2-256', SshBinary::readString($signatureBlob, $sigOff));
        $rawSig = SshBinary::readString($signatureBlob, $sigOff);
        self::assertNotSame('', $rawSig);
        self::assertSame(strlen($signatureBlob), $sigOff);

        $blobOff = 0;
        self::assertSame('ssh-rsa', SshBinary::readString($publicBlob, $blobOff));
        $e = SshBinary::readMpint($publicBlob, $blobOff);
        $n = SshBinary::readMpint($publicBlob, $blobOff);
        self::assertNotSame('', $e);
        self::assertNotSame('', $n);
        self::assertSame(strlen($publicBlob), $blobOff);

        $toSign = $this->canonicalToSign($sessionId, 'alice', $parts['algorithm'], $publicBlob);
        $details = openssl_pkey_get_details($parts['key']);
        self::assertIsArray($details);
        self::assertSame(1, openssl_verify($toSign, $rawSig, $details['key'], OPENSSL_ALGO_SHA256));
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
        self::assertSame(1, openssl_verify($toSign, $rawSig, $details['key'], $opensslAlgo));
    }

    /**
     * Пустой passphrase и null passphrase эквивалентны для незашифрованного PEM.
     */
    public function testLoadAcceptsNullAndEmptyPassphrase(): void
    {
        $pem = $this->rsaPem();
        $signer = new PublicKeySigner();
        $withNull = $signer->load(AuthCredentials::withPublicKey('u', $pem, null));
        $withEmpty = $signer->load(AuthCredentials::withPublicKey('u', $pem, ''));
        self::assertSame($withNull['algorithm'], $withEmpty['algorithm']);
        self::assertSame($withNull['publicBlob'], $withEmpty['publicBlob']);
    }

    /**
     * Зашифрованный PEM требует корректный passphrase (убивает Coalesce на ?? '').
     */
    public function testLoadEncryptedPemWithPassphrase(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $pem = '';
        self::assertTrue(openssl_pkey_export($key, $pem, 'wire-secret'));
        $signer = new PublicKeySigner();
        $parts = $signer->load(AuthCredentials::withPublicKey('enc', $pem, 'wire-secret'));
        self::assertSame('rsa-sha2-256', $parts['algorithm']);

        try {
            $signer->load(AuthCredentials::withPublicKey('enc', $pem, null));
            self::fail('ожидался ConnectionException без passphrase');
        } catch (ConnectionException $e) {
            self::assertStringStartsWith('Не удалось загрузить SSH private key:', $e->getMessage());
        }
    }

    /**
     * Сообщение об ошибке загрузки содержит фиксированный префикс.
     */
    public function testInvalidKeyMessageContainsPrefix(): void
    {
        $signer = new PublicKeySigner();

        try {
            $signer->load(AuthCredentials::withPublicKey('u', 'not-a-pem-key'));
            self::fail('ожидался ConnectionException');
        } catch (ConnectionException $e) {
            self::assertStringStartsWith('Не удалось загрузить SSH private key:', $e->getMessage());
            self::assertGreaterThan(
                strlen('Не удалось загрузить SSH private key: '),
                strlen($e->getMessage()),
            );
        }
    }

    /**
     * Неверный passphrase: префикс и текст openssl в сообщении.
     */
    public function testWrongPassphraseMessageContainsOpensslDetail(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $pem = '';
        self::assertTrue(openssl_pkey_export($key, $pem, 'right-pass'));
        $signer = new PublicKeySigner();

        try {
            $signer->load(AuthCredentials::withPublicKey('u', $pem, 'wrong-pass'));
            self::fail('ожидался ConnectionException');
        } catch (ConnectionException $e) {
            self::assertStringStartsWith('Не удалось загрузить SSH private key:', $e->getMessage());
            self::assertGreaterThan(
                strlen('Не удалось загрузить SSH private key: '),
                strlen($e->getMessage()),
            );
        }
    }

    /**
     * Пустой private key — отдельное сообщение (не openssl).
     */
    public function testEmptyPrivateKeyMessage(): void
    {
        $signer = new PublicKeySigner();

        try {
            $signer->load(AuthCredentials::withPublicKey('u', ''));
            self::fail('ожидался ConnectionException');
        } catch (ConnectionException $e) {
            self::assertSame('Пустой private key', $e->getMessage());
        }
    }

    /**
     * Загрузка из файла: путь существует, содержимое = PEM.
     */
    public function testLoadFromFilePath(): void
    {
        $pem = $this->rsaPem();
        $path = tempnam(sys_get_temp_dir(), 'ccsshwire');
        self::assertNotFalse($path);
        self::assertNotFalse(file_put_contents($path, $pem));

        try {
            $parts = (new PublicKeySigner())->load(AuthCredentials::withPublicKey('fileuser', $path));
            self::assertSame('rsa-sha2-256', $parts['algorithm']);
            self::assertStringStartsWith(SshBinary::sshString('ssh-rsa'), $parts['publicBlob']);
        } finally {
            $tempRoot = realpath(sys_get_temp_dir());
            $real = realpath($path);

            if ($tempRoot !== false && $real !== false && str_starts_with($real, $tempRoot)) {
                // Путь уже проверен realpath + префикс temp dir.
                unlink($real); // nosemgrep: php.lang.security.unlink-use.unlink-use
            }
        }
    }

    /**
     * Разные username дают разный request (убивает удаление username из concat).
     */
    public function testUsernameAffectsRequestBytes(): void
    {
        $pem = $this->rsaPem();
        $signer = new PublicKeySigner();
        $parts = $signer->load(AuthCredentials::withPublicKey('x', $pem));
        $a = $signer->buildRequest(str_repeat('z', 32), 'user-a', $parts);
        $b = $signer->buildRequest(str_repeat('z', 32), 'user-b', $parts);
        self::assertNotSame($a, $b);
        self::assertStringContainsString(SshBinary::sshString('user-a'), $a);
        self::assertStringContainsString(SshBinary::sshString('user-b'), $b);
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
     * Пустой файл ключа — отдельное сообщение с путём.
     */
    public function testEmptyKeyFileThrowsWithPath(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ccsshempty');
        self::assertNotFalse($path);
        self::assertSame(0, filesize($path));
        $signer = new PublicKeySigner();

        try {
            $signer->load(AuthCredentials::withPublicKey('u', $path));
            self::fail('ожидался ConnectionException');
        } catch (ConnectionException $e) {
            self::assertSame('Не удалось прочитать файл ключа: ' . $path, $e->getMessage());
        } finally {
            $tempRoot = realpath(sys_get_temp_dir());
            $real = realpath($path);

            if ($tempRoot !== false && $real !== false && str_starts_with($real, $tempRoot)) {
                // Путь уже проверен realpath + префикс temp dir.
                unlink($real); // nosemgrep: php.lang.security.unlink-use.unlink-use
            }
        }
    }

    /**
     * rsaParts отклоняет битый массив openssl rsa (LogicalAnd на n/e).
     */
    public function testRsaPartsRejectsMissingModulusOrExponent(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        $method = new ReflectionMethod(PublicKeySigner::class, 'rsaParts');
        $method->setAccessible(true);
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
        $method->setAccessible(true);
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
        self::assertSame(strlen($req), $offset);

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
     * ed25519Parts отклоняет некорректные pub/priv (reflection).
     */
    public function testEd25519PartsRejectsInvalidDetails(): void
    {
        if (!defined('OPENSSL_KEYTYPE_ED25519')) {
            self::markTestSkipped('OPENSSL_KEYTYPE_ED25519 недоступен');
        }

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_ED25519]);

        if ($key === false) {
            self::markTestSkipped('openssl не создал Ed25519 ключ');
        }

        $method = new ReflectionMethod(PublicKeySigner::class, 'ed25519Parts');
        $method->setAccessible(true);
        $signer = new PublicKeySigner();

        try {
            $method->invoke($signer, $key, null);
            self::fail('ожидался ConnectionException');
        } catch (ConnectionException $e) {
            self::assertSame('Ed25519: нет pub_key/priv_key в openssl details', $e->getMessage());
        }

        try {
            $method->invoke($signer, $key, ['pub_key' => str_repeat('a', 32), 'priv_key' => 'short']);
            self::fail('ожидался ConnectionException');
        } catch (ConnectionException $e) {
            self::assertSame('Ed25519: некорректная длина ключей', $e->getMessage());
        }
    }

    /**
     * Неподдерживаемый тип ключа — фиксированное сообщение.
     */
    public function testUnsupportedKeyTypeMessage(): void
    {
        $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        if ($ec === false) {
            self::markTestSkipped('openssl не создал EC ключ');
        }

        $pem = '';
        self::assertTrue(openssl_pkey_export($ec, $pem));
        $signer = new PublicKeySigner();

        try {
            $signer->load(AuthCredentials::withPublicKey('u', $pem));
            self::fail('ожидался ConnectionException');
        } catch (ConnectionException $e) {
            self::assertSame(
                'Поддерживаются RSA и Ed25519 private keys (PEM)',
                $e->getMessage(),
            );
        }
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
        self::assertNotSame('', $pem);

        return $pem;
    }
}
