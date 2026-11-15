<?php

namespace App\Service;

class DnsHostResolver implements HostResolverInterface
{
    public function resolve(string $host): array
    {
        $host = rtrim(strtolower(trim($host)), '.');
        if ($host === '') {
            return [];
        }

        $ips = [];
        foreach (gethostbynamel($host) ?: [] as $ip) {
            $ips[] = $ip;
        }

        if (function_exists('dns_get_record')) {
            foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
