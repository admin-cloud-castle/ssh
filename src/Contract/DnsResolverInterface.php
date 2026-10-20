<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Contract;

/**
 * Резолвер DNS для SSRF-guard.
 */
interface DnsResolverInterface
{
    /**
     * Резолвит hostname в список IP.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}