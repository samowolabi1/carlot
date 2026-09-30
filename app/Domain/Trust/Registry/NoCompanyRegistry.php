<?php

namespace App\Domain\Trust\Registry;

/** No automatic lookup: admins compare the certificate by hand. */
final class NoCompanyRegistry implements CompanyRegistry
{
    public function enabled(): bool
    {
        return false;
    }

    public function lookup(string $number): ?CompanyRecord
    {
        return null;
    }
}
