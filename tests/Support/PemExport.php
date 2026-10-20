<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Tests\Support;

use OpenSSLAsymmetricKey;
use PHPUnit\Framework\Assert;

/**
 * Экспорт PEM с узким типом string для PHPStan/Psalm.
 *
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.ShortVariable")
 */
final class PemExport
{
    /**
     * Экспортирует ключ в PEM; при ошибке валит тест.
     *
     * @param OpenSSLAsymmetricKey $key        Приватный ключ openssl
     * @param string|null          $passphrase Пароль PEM или null
     *
     * @return non-empty-string
     */
    public static function privateKey(OpenSSLAsymmetricKey $key, ?string $passphrase = null): string
    {
        $pem = '';
        $exportedOk = $passphrase === null
            ? openssl_pkey_export($key, $pem)
            : openssl_pkey_export($key, $pem, $passphrase);

        $exported = NonEmptyString::tryFrom($pem);

        if (!$exportedOk || $exported === null) {
            Assert::fail('openssl_pkey_export failed');
        }

        return $exported;
    }
}
