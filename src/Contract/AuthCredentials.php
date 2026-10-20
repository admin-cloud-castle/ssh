<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Contract;

/**
 * Учётные данные SSH: password и/или publickey.
 */
final class AuthCredentials
{
    /**
     * @param string      $username   Имя пользователя
     * @param string|null $password   Пароль или null
     * @param string|null $privateKey PEM или путь к ключу
     * @param string|null $passphrase Пароль к ключу
     */
    private function __construct(
        private readonly string $username,
        private readonly ?string $password,
        private readonly ?string $privateKey,
        private readonly ?string $passphrase,
    ) {
    }

    /**
     * Создаёт credentials с паролем.
     */
    public static function withPassword(string $username, string $password): self
    {
        return new self($username, $password, null, null);
    }

    /**
     * Создаёт credentials с private key.
     */
    public static function withPublicKey(
        string $username,
        string $privateKey,
        ?string $passphrase = null,
    ): self {
        return new self($username, null, $privateKey, $passphrase);
    }

    /**
     * Имя пользователя.
     */
    public function username(): string
    {
        return $this->username;
    }

    /**
     * Пароль или null.
     */
    public function password(): ?string
    {
        return $this->password;
    }

    /**
     * PEM/путь ключа или null.
     */
    public function privateKey(): ?string
    {
        return $this->privateKey;
    }

    /**
     * Passphrase ключа или null.
     */
    public function passphrase(): ?string
    {
        return $this->passphrase;
    }
}
