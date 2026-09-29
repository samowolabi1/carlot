<?php

namespace App\Domain\Lots\Domains;

class SystemDnsLookup implements DnsLookup
{
    public function txt(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT) ?: [];

        return array_map(fn (array $r) => (string) ($r['txt'] ?? implode('', $r['entries'] ?? [])), $records);
    }
}
