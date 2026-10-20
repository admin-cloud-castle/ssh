<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Contract;

/**
 * Опции TCP/SSH-соединения и политики безопасности.
 *
 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
 * @SuppressWarnings("PHPMD.LongVariable")
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final class ConnectionOptions
{
    /**
     * @param float             $connectTimeout        Таймаут TCP connect, сек
     * @param float             $ioTimeout             Таймаут чтения/записи, сек
     * @param bool              $blockPrivateAddresses Блокировать private/reserved IP (SSRF)
     * @param list<string>|null $trustedHostKeys       SHA256 fingerprints host key (base64 без префикса)
     * @param bool              $strictHostKey         true — требовать совпадение fingerprint
     */
    public function __construct(
        private readonly float $connectTimeout = 10.0,
        private readonly float $ioTimeout = 30.0,
        private readonly bool $blockPrivateAddresses = true,
        private readonly ?array $trustedHostKeys = null,
        private readonly bool $strictHostKey = false,
    ) {
    }

    /**
     * Значения по умолчанию.
     */
    public static function defaults(): self
    {
        return new self();
    }

    /**
     * Таймаут connect.
     */
    public function connectTimeout(): float
    {
        return $this->connectTimeout;
    }

    /**
     * Таймаут I/O.
     */
    public function ioTimeout(): float
    {
        return $this->ioTimeout;
    }

    /**
     * Включён ли SSRF-guard.
     */
    public function blockPrivateAddresses(): bool
    {
        return $this->blockPrivateAddresses;
    }

    /**
     * Список доверенных fingerprint или null.
     *
     * @return list<string>|null
     */
    public function trustedHostKeys(): ?array
    {
        return $this->trustedHostKeys;
    }

    /**
     * Строгая проверка host key.
     */
    public function strictHostKey(): bool
    {
        return $this->strictHostKey;
    }

    /**
     * Клон с отключённым SSRF-guard (для локальных тестов).
     */
    public function allowPrivateAddresses(): self
    {
        return new self(
            $this->connectTimeout,
            $this->ioTimeout,
            false,
            $this->trustedHostKeys,
            $this->strictHostKey,
        );
    }

    /**
     * Клон с доверенными host keys.
     *
     * @param list<string> $fingerprints
     */
    public function withTrustedHostKeys(array $fingerprints, bool $strict = true): self
    {
        return new self(
            $this->connectTimeout,
            $this->ioTimeout,
            $this->blockPrivateAddresses,
            $fingerprints,
            $strict,
        );
    }
}
