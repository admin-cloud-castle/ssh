<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Contract;

/**
 * Неизменяемые опции TCP/SSH-соединения и политики безопасности (SSRF, host key).
 *
 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
 * @SuppressWarnings("PHPMD.LongVariable")
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
final class ConnectionOptions
{
    /**
     * Создаёт набор опций соединения.
     *
     * @param float             $connectTimeout        Таймаут TCP connect, секунды (>0 рекомендуется)
     * @param float             $ioTimeout             Таймаут чтения/записи stream, секунды
     * @param bool              $blockPrivateAddresses true — блокировать private/reserved IP (SSRF-guard)
     * @param list<string>|null $trustedHostKeys Доверенные SHA-256 fingerprints (base64, опц. префикс SHA256:)
     * @param bool              $strictHostKey   true — требовать совпадение fingerprint со списком
     */
    public function __construct(
        /** Таймаут TCP connect, секунды. @var float */
        private readonly float $connectTimeout = 10.0,
        /** Таймаут I/O stream, секунды. @var float */
        private readonly float $ioTimeout = 30.0,
        /** Блокировка private/reserved IP (SSRF-guard). @var bool */
        private readonly bool $blockPrivateAddresses = true,
        /** Доверенные SHA-256 fingerprints или null. @var list<string>|null */
        private readonly ?array $trustedHostKeys = null,
        /** Строгая проверка host key. @var bool */
        private readonly bool $strictHostKey = false,
    ) {
    }

    /**
     * Возвращает опции с значениями по умолчанию (SSRF включён, host key не строгий).
     *
     * @return self Новый экземпляр defaults
     */
    public static function defaults(): self
    {
        return new self();
    }

    /**
     * Возвращает таймаут TCP connect в секундах.
     *
     * @return float Секунды (как задано в конструкторе)
     */
    public function connectTimeout(): float
    {
        return $this->connectTimeout;
    }

    /**
     * Возвращает таймаут I/O stream в секундах.
     *
     * @return float Секунды (как задано в конструкторе)
     */
    public function ioTimeout(): float
    {
        return $this->ioTimeout;
    }

    /**
     * Проверяет, включён ли SSRF-guard (блокировка private/reserved адресов).
     *
     * @return bool true — private IP запрещены
     */
    public function blockPrivateAddresses(): bool
    {
        return $this->blockPrivateAddresses;
    }

    /**
     * Возвращает список доверенных fingerprint host key или null (список не задан).
     *
     * @return list<string>|null null — проверка по списку не выполняется (кроме strict без списка)
     */
    public function trustedHostKeys(): ?array
    {
        return $this->trustedHostKeys;
    }

    /**
     * Проверяет, требуется ли строгое совпадение host key.
     *
     * @return bool true — без совпадения fingerprint соединение отклоняется
     */
    public function strictHostKey(): bool
    {
        return $this->strictHostKey;
    }

    /**
     * Возвращает клон с отключённым SSRF-guard (локальные тесты / доверенная сеть).
     *
     * @return self Новый экземпляр с blockPrivateAddresses=false
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
     * Возвращает клон с заданным списком доверенных host key fingerprints.
     *
     * @param list<string> $fingerprints SHA-256 fingerprints (base64, допускается префикс SHA256:)
     * @param bool         $strict       true — требовать попадание в список
     *
     * @return self Новый экземпляр с обновлённой политикой host key
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
