<?php

declare(strict_types=1);

namespace CloudCastle\Ssh\Security;

use CloudCastle\Ssh\Contract\DnsResolverInterface;

/**
 * Системный DNS через dns_get_record / gethostbynamel.
 *
 * @SuppressWarnings("PHPMD.ErrorControlOperator")
 */
final class PhpDnsResolver implements DnsResolverInterface
{
    /**
     * {@inheritdoc}
     */
    #[\Override]
    public function resolve(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }

                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ips[] = $record['ip'];
                }

                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $fallback = @gethostbynamel($host);

            if (is_array($fallback)) {
                foreach ($fallback as $ip) {
                    if (is_string($ip)) {
                        $ips[] = $ip;
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }
}