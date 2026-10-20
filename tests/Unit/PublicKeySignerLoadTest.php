<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Unit;

use CloudCastle\Ssh\Auth\PublicKeySigner;
use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Wire\SshBinary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Загрузка PEM/файла и сообщения ошибок PublicKeySigner.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ShortVariable")
 */
#[CoversClass(PublicKeySigner::class)]
#[CoversClass(AuthCredentials::class)]
final class PublicKeySignerLoadTest extends TestCase
{
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
     * Неподдерживаемый тип ключа — фиксированное сообщение.
     */
    public function testUnsupportedKeyTypeMessage(): void
    {
        $ecKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        if ($ecKey === false) {
            self::markTestSkipped('openssl не создал EC ключ');
        }

        $pem = '';
        self::assertTrue(openssl_pkey_export($ecKey, $pem));
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
