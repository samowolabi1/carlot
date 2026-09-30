<?php

namespace App\Domain\Trust\Registry;

use Carbon\CarbonImmutable;

/** A company as the CAC registry has it. */
final readonly class CompanyRecord
{
    public function __construct(
        public string $name,
        public ?string $status = null,
        public ?CarbonImmutable $registeredOn = null,
        public ?string $address = null,
    ) {}
}
