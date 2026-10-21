<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Security;

/**
 * Результат подготовки endpoint: адрес для connect, исходный host, IP и порт.
 */
final class PinnedEndpoint
{
    /**
     * Создаёт описание pinned endpoint после SSRF-проверки.
     *
     * @param string       $connectEndpoint Строка для stream_socket_client (часто с IP вместо DNS)
     * @param string|null  $originalHost    Исходный hostname или null, если host не выделялся
     * @param list<string> $resolvedIps     Публичные IP из DNS (может быть пустым при allowPrivate)
     * @param int          $port            TCP-порт (обычно 22, если не указан в endpoint)
     */
    public function __construct(
        /** Endpoint для TCP connect (часто с pinned IP). @var string */
        private readonly string $connectEndpoint,
        /** Исходный hostname или null. @var string|null */
        private readonly ?string $originalHost,
        /** Публичные IP из DNS. @var list<string> */
        private readonly array $resolvedIps,
        /** TCP-порт (0 — не задан явно). @var int */
        private readonly int $port,
    ) {
    }

    /**
     * Возвращает endpoint, который следует использовать для TCP connect.
     *
     * @return string Хост/IP[:порт] или URL-подобная строка
     */
    public function connectEndpoint(): string
    {
        return $this->connectEndpoint;
    }

    /**
     * Возвращает исходный host либо, при его отсутствии, connectEndpoint.
     *
     * @return string Hostname, IP или полный connectEndpoint
     */
    public function host(): string
    {
        return $this->originalHost ?? $this->connectEndpoint;
    }

    /**
     * Возвращает TCP-порт endpoint.
     *
     * @return int Порт ≥0; 0 означает «не задан» на стороне вызывающего кода
     */
    public function port(): int
    {
        return $this->port;
    }

    /**
     * Возвращает список IP, полученных при DNS-resolve.
     *
     * @return list<string> Публичные адреса; [] если resolve не выполнялся
     */
    public function resolvedIps(): array
    {
        return $this->resolvedIps;
    }
}
