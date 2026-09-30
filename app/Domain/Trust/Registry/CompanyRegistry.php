<?php

namespace App\Domain\Trust\Registry;

/**
 * Looks up a CAC number (TDD M14). Drivers: `none` (admins check by hand; nothing is looked up) and
 * `dojah` (Dojah's CAC lookup). Tests bind Tests\Support\FakeCompanyRegistry.
 */
interface CompanyRegistry
{
    public function enabled(): bool;

    /**
     * @param  string  $number  as stored: digits for companies (RC), "BN…" business names, "IT…" trustees
     * @return CompanyRecord|null null when the registry has no such number
     *
     * @throws RegistryUnavailable
     */
    public function lookup(string $number): ?CompanyRecord;
}
