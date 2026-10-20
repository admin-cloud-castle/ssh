<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Security;

use CloudCastle\Ssh\Contract\DnsResolverInterface;

/**
 * Системный DNS через dns_get_record / gethostbynamel.
 *
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 * @SuppressWarnings("PHPMD.ShortVariable")
 * @SuppressWarnings("PHPMD.CyclomaticComplexity")
 */
final class PhpDnsResolver implements DnsResolverInterface
{
    /**
     * {@inheritdoc}
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
     * Собирает A/AAAA из dns_get_record.
     *
     * @return list<string>
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
     * Извлекает IP из строки результата dns_get_record.
     *
     * @param array{type?: string, ip?: string, ipv6?: string} $record
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
     * Fallback через gethostbynamel (только IPv4).
     *
     * @return list<string>
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
