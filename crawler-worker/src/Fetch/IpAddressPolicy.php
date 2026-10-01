<?php
declare(strict_types=1);
namespace Scholarship\CrawlerWorker\Fetch;
final class IpAddressPolicy
{
    public function isPublic(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) return false;
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            return $this->isPublic(inet_ntop(substr($packed, 12)) ?: '');
        }
        if (strlen($packed) === 16) {
            $first = ord($packed[0]);
            if ($first < 0x20 || $first > 0x3f) return false; // Only IPv6 global-unicast 2000::/3 is eligible.
        }
        if (strlen($packed) === 4) {
            if ($address==='168.63.129.16') return false; // Azure platform/metadata virtual IP.
            foreach ([['0.0.0.0',8],['100.64.0.0',10],['192.0.0.0',24],['192.0.2.0',24],['192.88.99.0',24],['198.18.0.0',15],['198.51.100.0',24],['203.0.113.0',24],['224.0.0.0',4],['240.0.0.0',4]] as [$cidr,$bits]) {
                if ($this->inCidr($packed,inet_pton($cidr),$bits)) return false;
            }
        }
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
    public function assertAllPublic(array $addresses): void
    {
        if ($addresses === []) throw new FetchFailure('DNS_FAILURE', true, 'No destination addresses resolved.');
        foreach ($addresses as $address) if (! $this->isPublic((string) $address)) throw new FetchFailure('UNSAFE_IP', false, 'Resolved destination is not globally routable.');
    }
    private function inCidr(string $address, string|false $network, int $prefix): bool
    {
        if ($network === false || strlen($address)!==strlen($network)) return false;
        $whole=intdiv($prefix,8); $remaining=$prefix%8;
        if ($whole>0 && substr($address,0,$whole)!==substr($network,0,$whole)) return false;
        if ($remaining===0) return true;
        $mask=(0xff << (8-$remaining)) & 0xff;
        return (ord($address[$whole]) & $mask)===(ord($network[$whole]) & $mask);
    }
}
