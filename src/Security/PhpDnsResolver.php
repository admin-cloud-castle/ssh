<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Security;

use CloudCastle\Ssh\Contract\DnsResolverInterface;

/**
 * Системный DNS-резолвер: dns_get_record (A/AAAA) с fallback на gethostbynamel.
 *
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 * @SuppressWarnings("PHPMD.ShortVariable")
 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
 */
final class PhpDnsResolver implements DnsResolverInterface
{
    /**
     * Резолвит hostname в список IP; для IP-литерала возвращает `[$host]` без DNS.
     *
     * @param string $host Имя хоста или IPv4/IPv6 литерал
     *
     * @return list<string> Уникальные адреса; [] если DNS не ответил
     */
    #[\Override]
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = $this->fromDnsRecords($host);

        return $ips !== [] ? $ips : $this->fromGethostbynamel($host);
    }

    /**
     * Собирает A/AAAA записи через dns_get_record.
     *
     * @param string $host Имя хоста
     *
     * @return list<string> Уникальные IP из успешных записей
     */
    private function fromDnsRecords(string $host): array
    {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if ($records === false) { // @codeCoverageIgnore
            return []; // @codeCoverageIgnore
        }

        $ips = [];

        foreach ($records as $record) {
            /** @var array{type?: string, ip?: string, ipv6?: string} $shaped */
            $shaped = $record;
            $ip = $this->ipFromDnsRow($shaped);

            if ($ip !== null) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Извлекает IP из одной строки результата dns_get_record.
     *
     * @param array{type?: string, ip?: string, ipv6?: string} $record Строка A или AAAA
     *
     * @return string|null IPv4/IPv6 или null для прочих типов / пустых полей
     *
     * @psalm-suppress MixedAssignment
     */
    private function ipFromDnsRow(array $record): ?string
    {
        $type = $record['type'] ?? null;

        if ($type === 'A') {
            return $record['ip'] ?? null;
        }

        if ($type === 'AAAA') {
            return $record['ipv6'] ?? null;
        }

        return null;
    }

    /**
     * Fallback-резолв только IPv4 через gethostbynamel.
     *
     * @param string $host Имя хоста
     *
     * @return list<string> Уникальные IPv4; [] при false от gethostbynamel
     */
    private function fromGethostbynamel(string $host): array
    {
        $list = @gethostbynamel($host);

        if ($list === false) { // @codeCoverageIgnore
            return []; // @codeCoverageIgnore
        }

        return array_values(array_unique($list));
    }
}
