<?php

namespace App\Domain\Lots\Domains;

/** DNS lookups behind an interface, so tests never touch the network. */
interface DnsLookup
{
    /** @return list<string> TXT values for the host */
    public function txt(string $host): array;
}
