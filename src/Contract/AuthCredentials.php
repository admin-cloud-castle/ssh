<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Contract;

/**
 * Неизменяемые учётные данные SSH: password и/или publickey (PEM / путь к файлу).
 */
final class AuthCredentials
{
    /**
     * Создаёт набор credentials (только через withPassword / withPublicKey).
     *
     * @param string      $username   Имя пользователя на SSH-сервере
     * @param string|null $password   Пароль; null если используется только ключ
     * @param string|null $privateKey PEM-текст или путь к файлу ключа; null для password-auth
     * @param string|null $passphrase Пароль к зашифрованному private key; null если не нужен
     */
    private function __construct(
        /** Имя пользователя SSH. @var string */
        private readonly string $username,
        /** Пароль или null при publickey. @var string|null */
        private readonly ?string $password,
        /** PEM/путь ключа или null при password. @var string|null */
        private readonly ?string $privateKey,
        /** Passphrase ключа или null. @var string|null */
        private readonly ?string $passphrase,
    ) {
    }

    /**
     * Создаёт credentials для USERAUTH password.
     *
     * @param string $username Имя пользователя
     * @param string $password Пароль (может быть пустой строкой — сервер отклонит)
     *
     * @return self Новый экземпляр без private key
     */
    public static function withPassword(string $username, string $password): self
    {
        return new self($username, $password, null, null);
    }

    /**
     * Создаёт credentials для USERAUTH publickey.
     *
     * @param string      $username   Имя пользователя
     * @param string      $privateKey PEM или путь к файлу ключа (RSA / Ed25519)
     * @param string|null $passphrase Passphrase ключа или null
     *
     * @return self Новый экземпляр без password
     */
    public static function withPublicKey(
        string $username,
        string $privateKey,
        ?string $passphrase = null,
    ): self {
        return new self($username, null, $privateKey, $passphrase);
    }

    /**
     * Возвращает имя пользователя SSH.
     *
     * @return string Non-empty с точки зрения вызывающего кода (не валидируется здесь)
     */
    public function username(): string
    {
        return $this->username;
    }

    /**
     * Возвращает пароль или null, если выбран publickey.
     *
     * @return string|null Пароль либо null
     */
    public function password(): ?string
    {
        return $this->password;
    }

    /**
     * Возвращает PEM/путь ключа или null, если выбран password.
     *
     * @return string|null Материал ключа либо null
     */
    public function privateKey(): ?string
    {
        return $this->privateKey;
    }

    /**
     * Возвращает passphrase ключа или null.
     *
     * @return string|null Passphrase либо null
     */
    public function passphrase(): ?string
    {
        return $this->passphrase;
    }
}
