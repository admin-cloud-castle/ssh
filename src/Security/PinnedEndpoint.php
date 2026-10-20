<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Security;

/**
 * Endpoint после DNS-pinning.
 */
final class PinnedEndpoint
{
    /**
     * @param string       $connectEndpoint Endpoint для connect (возможно с IP)
     * @param string|null  $originalHost    Исходный hostname
     * @param list<string> $resolvedIps     Резолвнутые публичные IP
     * @param int          $port            TCP-порт
     */
    public function __construct(
        private readonly string $connectEndpoint,
        private readonly ?string $originalHost,
        private readonly array $resolvedIps,
        private readonly int $port,
    ) {
    }

    /**
     * Endpoint для connect.
     */
    public function connectEndpoint(): string
    {
        return $this->connectEndpoint;
    }

    /**
     * Исходный host или IP.
     */
    public function host(): string
    {
        return $this->originalHost ?? $this->connectEndpoint;
    }

    /**
     * TCP-порт.
     */
    public function port(): int
    {
        return $this->port;
    }

    /**
     * Список IP.
     *
     * @return list<string>
     */
    public function resolvedIps(): array
    {
        return $this->resolvedIps;
    }
}