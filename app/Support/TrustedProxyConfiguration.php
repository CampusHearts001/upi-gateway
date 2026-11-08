<?php

namespace App\Support;

use InvalidArgumentException;

class TrustedProxyConfiguration
{
    /** @return list<string> */
    public static function addresses(?string $value): array
    {
        if (trim((string) $value) === '') {
            return [];
        }

        $addresses = array_map('trim', explode(',', $value));
        foreach ($addresses as $address) {
            [$ip, $prefix] = array_pad(explode('/', $address, 2), 2, null);
            $isIp = filter_var($ip, FILTER_VALIDATE_IP) !== false;
            $maximumPrefix = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 32 : 128;
            if (! $isIp || ($prefix !== null && (! ctype_digit($prefix) || (int) $prefix < 1 || (int) $prefix > $maximumPrefix))) {
                throw new InvalidArgumentException('TRUSTED_PROXIES must contain explicit IP addresses or CIDR ranges.');
            }
        }

        return $addresses;
    }
}
