<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Auth;

use CloudCastle\Ssh\Contract\AuthCredentials;
use CloudCastle\Ssh\Exception\ConnectionException;
use CloudCastle\Ssh\Wire\SshBinary;
use OpenSSLAsymmetricKey;

/**
 * Загрузка PEM private key и подпись USERAUTH publickey (RSA / Ed25519).
 *
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
 * @SuppressWarnings("PHPMD.StaticAccess")
 * @SuppressWarnings("PHPMD.NPathComplexity")
 * @SuppressWarnings("PHPMD.ShortVariable")
 * @SuppressWarnings("PHPMD.ElseExpression")
 */
final class PublicKeySigner
{
    /**
     * Загружает ключ из AuthCredentials и готовит поля для publickey auth.
     *
     * @param AuthCredentials $auth Учётные данные с privateKey (PEM или путь к файлу)
     *
     * @return array{
     *     algorithm: string,
     *     publicBlob: string,
     *     key: OpenSSLAsymmetricKey,
     *     ed25519Secret: string|null
     * } algorithm — rsa-sha2-256 или ssh-ed25519; ed25519Secret — 64 байта seed||pub или null для RSA
     *
     * @throws ConnectionException При пустом/битом ключе или неподдерживаемом типе
     */
    public function load(AuthCredentials $auth): array
    {
        $material = $this->readMaterial($auth->privateKey() ?? '');
        $passphrase = $auth->passphrase() ?? '';
        $key = openssl_pkey_get_private($material, $passphrase);

        if ($key === false) {
            $error = openssl_error_string();

            throw new ConnectionException(
                'Не удалось загрузить SSH private key: ' . ($error !== false ? $error : ''),
            );
        }

        $details = openssl_pkey_get_details($key);

        if (!is_array($details) || !isset($details['type']) || !is_int($details['type'])) { // @codeCoverageIgnore
            throw new ConnectionException('Не удалось прочитать параметры SSH-ключа'); // @codeCoverageIgnore
        }

        $type = $details['type'];

        if ($type === OPENSSL_KEYTYPE_RSA) {
            return $this->rsaParts($key, $details['rsa'] ?? null);
        }

        // @codeCoverageIgnoreStart
        if (
            defined('OPENSSL_KEYTYPE_ED25519')
            && $type === constant('OPENSSL_KEYTYPE_ED25519')
        ) {
            return $this->ed25519Parts($key, $details['ed25519'] ?? null);
        }
        // @codeCoverageIgnoreEnd

        throw new ConnectionException('Поддерживаются RSA и Ed25519 private keys (PEM)');
    }

    /**
     * Собирает SSH_MSG_USERAUTH_REQUEST publickey с подписью.
     *
     * @param string $sessionId Идентификатор сессии из exchange hash H
     * @param string $username  Имя пользователя на сервере
     * @param array{
     *     algorithm: string,
     *     publicBlob: string,
     *     key: OpenSSLAsymmetricKey,
     *     ed25519Secret: string|null
     * } $parts Результат load()
     *
     * @return string Полный payload USERAUTH_REQUEST (type byte = 50)
     *
     * @throws ConnectionException Если подпись не удалась
     */
    public function buildRequest(string $sessionId, string $username, array $parts): string
    {
        $signatureBlob = $this->sign($sessionId, $username, $parts);

        return chr(50)
            . SshBinary::sshString($username)
            . SshBinary::sshString('ssh-connection')
            . SshBinary::sshString('publickey')
            . "\x01"
            . SshBinary::sshString($parts['algorithm'])
            . SshBinary::sshString($parts['publicBlob'])
            . SshBinary::sshString($signatureBlob);
    }

    /**
     * Подписывает данные USERAUTH publickey и упаковывает signature blob.
     *
     * @param string $sessionId Идентификатор сессии H
     * @param string $username  Имя пользователя
     * @param array{
     *     algorithm: string,
     *     publicBlob: string,
     *     key: OpenSSLAsymmetricKey,
     *     ed25519Secret: string|null
     * } $parts Поля ключа
     *
     * @return string SSH string(algorithm) + SSH string(raw signature)
     *
     * @throws ConnectionException Если openssl/sodium подпись не удалась
     */
    private function sign(string $sessionId, string $username, array $parts): string
    {
        $toSign = SshBinary::sshString($sessionId)
            . chr(50)
            . SshBinary::sshString($username)
            . SshBinary::sshString('ssh-connection')
            . SshBinary::sshString('publickey')
            . "\x01"
            . SshBinary::sshString($parts['algorithm'])
            . SshBinary::sshString($parts['publicBlob']);

        $algorithm = $parts['algorithm'];

        $signature = match ($algorithm) {
            'ssh-ed25519' => $this->signEd25519($toSign, $parts), // @codeCoverageIgnore
            'rsa-sha2-512' => $this->opensslSign($toSign, $parts['key'], OPENSSL_ALGO_SHA512, 'RSA-SHA512'),
            'rsa-sha2-256' => $this->opensslSign($toSign, $parts['key'], OPENSSL_ALGO_SHA256, 'RSA-SHA256'),
            default => $this->opensslSign($toSign, $parts['key'], OPENSSL_ALGO_SHA1, 'RSA-SHA1'),
        };

        return SshBinary::sshString($algorithm) . SshBinary::sshString($signature);
    }

    /**
     * Подписывает данные через openssl_sign.
     *
     * @param string               $toSign    Байты для подписи
     * @param OpenSSLAsymmetricKey $key       Закрытый ключ
     * @param int                  $algorithm Константа OPENSSL_ALGO_*
     * @param string               $label     Метка для текста исключения
     *
     * @return string Сырая подпись (не SSH-обёрнутая)
     *
     * @throws ConnectionException При сбое openssl_sign или пустой подписи
     */
    private function opensslSign(string $toSign, OpenSSLAsymmetricKey $key, int $algorithm, string $label): string
    {
        $signature = '';
        $signed = openssl_sign($toSign, $signature, $key, $algorithm);

        if (!$signed) { // @codeCoverageIgnore
            throw new ConnectionException($label . ' подпись не удалась'); // @codeCoverageIgnore
        }

        return $this->requireSignatureBytes($signature, $label);
    }

    /**
     * Подписывает данные Ed25519 (sodium или openssl).
     *
     * @param string $toSign Байты для подписи
     * @param array{
     *     algorithm: string,
     *     publicBlob: string,
     *     key: OpenSSLAsymmetricKey,
     *     ed25519Secret: string|null
     * } $parts Поля ключа; предпочтителен ed25519Secret для sodium
     *
     * @return string 64-байтовая подпись Ed25519
     *
     * @throws ConnectionException Если оба способа подписи недоступны/упали
     */
    private function signEd25519(string $toSign, array $parts): string
    {
        // @codeCoverageIgnoreStart
        $secret = $parts['ed25519Secret'];

        if (is_string($secret) && $secret !== '' && function_exists('sodium_crypto_sign_detached')) {
            return sodium_crypto_sign_detached($toSign, $secret);
        }

        $signature = '';
        $signed = openssl_sign($toSign, $signature, $parts['key'], 0);

        if (!$signed) {
            $error = openssl_error_string();

            throw new ConnectionException(
                'Ed25519 подпись не удалась: ' . ($error !== false ? $error : ''),
            );
        }

        return $this->requireSignatureBytes($signature, 'Ed25519');
        // @codeCoverageIgnoreEnd
    }

    /**
     * Проверяет, что подпись — непустая строка.
     *
     * @param mixed  $signature Результат openssl_sign (by-ref)
     * @param string $label     Метка для текста исключения
     *
     * @return string Непустые байты подписи
     *
     * @throws ConnectionException Если значение не string или пустое
     */
    private function requireSignatureBytes(mixed $signature, string $label): string
    {
        if (!is_string($signature) || $signature === '') { // @codeCoverageIgnore
            throw new ConnectionException($label . ' подпись не удалась'); // @codeCoverageIgnore
        }

        return $signature;
    }

    /**
     * Читает PEM из строки или из файла по пути.
     *
     * @param string $privateKey PEM-текст или абсолютный/относительный путь к файлу
     *
     * @return string Содержимое PEM
     *
     * @throws ConnectionException При пустом вводе или ошибке чтения файла
     */
    private function readMaterial(string $privateKey): string
    {
        if ($privateKey === '') {
            throw new ConnectionException('Пустой private key');
        }

        if (is_file($privateKey)) {
            $contents = file_get_contents($privateKey);

            if (!is_string($contents) || $contents === '') { // @codeCoverageIgnore
                throw new ConnectionException('Не удалось прочитать файл ключа: ' . $privateKey); // @codeCoverageIgnore
            }

            return $contents;
        }

        return $privateKey;
    }

    /**
     * Собирает публичный blob ssh-rsa и алгоритм rsa-sha2-256.
     *
     * @param OpenSSLAsymmetricKey $key Закрытый RSA-ключ
     * @param mixed                $rsa Массив openssl details['rsa'] с ключами n и e
     *
     * @return array{
     *     algorithm: string,
     *     publicBlob: string,
     *     key: OpenSSLAsymmetricKey,
     *     ed25519Secret: null
     * }
     *
     * @throws ConnectionException Если нет модуля/экспоненты
     */
    private function rsaParts(OpenSSLAsymmetricKey $key, mixed $rsa): array
    {
        $rsaOk = is_array($rsa) && isset($rsa['n'], $rsa['e']) && is_string($rsa['n']) && is_string($rsa['e']);
        if (!$rsaOk) { // @codeCoverageIgnore
            throw new ConnectionException('RSA-ключ без модуля/экспоненты'); // @codeCoverageIgnore
        }

        $blob = SshBinary::sshString('ssh-rsa')
            . SshBinary::mpint($rsa['e'])
            . SshBinary::mpint($rsa['n']);

        return [
            'algorithm' => 'rsa-sha2-256',
            'publicBlob' => $blob,
            'key' => $key,
            'ed25519Secret' => null,
        ];
    }

    /**
     * Собирает публичный blob ssh-ed25519 и secret для sodium.
     *
     * @param OpenSSLAsymmetricKey $key Закрытый Ed25519-ключ
     * @param mixed $ed25519Details Массив openssl details['ed25519'] с pub_key/priv_key по 32 байта
     *
     * @return array{
     *     algorithm: string,
     *     publicBlob: string,
     *     key: OpenSSLAsymmetricKey,
     *     ed25519Secret: string
     * }
     *
     * @throws ConnectionException При отсутствии или неверной длине ключей
     */
    private function ed25519Parts(OpenSSLAsymmetricKey $key, mixed $ed25519Details): array
    {
        // @codeCoverageIgnoreStart
        if (!is_array($ed25519Details) || !isset($ed25519Details['pub_key'], $ed25519Details['priv_key'])) {
            throw new ConnectionException('Ed25519: нет pub_key/priv_key в openssl details');
        }

        $pub = $ed25519Details['pub_key'];
        $priv = $ed25519Details['priv_key'];

        if (!is_string($pub) || strlen($pub) !== 32 || !is_string($priv) || strlen($priv) !== 32) {
            throw new ConnectionException('Ed25519: некорректная длина ключей');
        }

        $blob = SshBinary::sshString('ssh-ed25519') . SshBinary::sshString($pub);

        return [
            'algorithm' => 'ssh-ed25519',
            'publicBlob' => $blob,
            'key' => $key,
            'ed25519Secret' => $priv . $pub,
        ];
        // @codeCoverageIgnoreEnd
    }
}
