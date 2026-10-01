<?php
declare(strict_types=1);
namespace Scholarship\CrawlerWorker\Fetch;
use Scholarship\CrawlerWorker\Exception\FetchFailure;
final class SystemDnsResolver implements DnsResolverInterface
{
    public function resolve(string $hostname): array
    {
        if (filter_var($hostname, FILTER_VALIDATE_IP)) return [$hostname];
        $addresses = [];
        foreach (dns_get_record($hostname, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) $addresses[] = $ip;
        }
        $addresses = array_values(array_unique($addresses));
        if ($addresses === []) throw new FetchFailure('DNS_FAILURE', true, 'Source hostname did not resolve.');
        return $addresses;
    }
}
