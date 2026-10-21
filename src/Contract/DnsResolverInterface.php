<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Contract;

/**
 * Резолвер DNS для SSRF-guard: hostname → список IP до connect.
 */
interface DnsResolverInterface
{
    /**
     * Резолвит hostname в список IPv4/IPv6 адресов.
     *
     * Для уже валидного IP-литерала реализация может вернуть `[$host]` без запроса к DNS.
     *
     * @param string $host Имя хоста или IP-литерал (без порта и скобок)
     *
     * @return list<string> Уникальные IP в порядке получения; пустой список — «не резолвится»
     */
    public function resolve(string $host): array;
}
