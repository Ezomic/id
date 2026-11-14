<?php

declare(strict_types=1);

namespace App\Services;

use Symfony\Component\HttpFoundation\IpUtils;

class PublicAddressResolver
{
    /**
     * Not reserved in PHP's filter, but never a public website either:
     * carrier-grade NAT, benchmarking and multicast.
     */
    private const EXTRA_BLOCKED = ['100.64.0.0/10', '198.18.0.0/15', '224.0.0.0/4', 'ff00::/8'];

    /**
     * An address to connect to for $host, or null when it resolves to nothing
     * or to anything that is not public. Every address must pass, so a name
     * with one public and one internal record cannot be used to reach the
     * internal one.
     */
    public function resolve(string $host): ?string
    {
        $addresses = $this->addresses(trim($host, '[]'));

        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                return null;
            }
        }

        return $addresses[0];
    }

    /**
     * @return list<string>
     */
    protected function addresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $v4 = gethostbynamel($host) ?: [];
        $v6 = array_filter(
            array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
            fn (mixed $address): bool => is_string($address),
        );

        return array_values(array_unique([...$v4, ...$v6]));
    }

    private function isPublic(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && ! IpUtils::checkIp($address, self::EXTRA_BLOCKED);
    }
}
