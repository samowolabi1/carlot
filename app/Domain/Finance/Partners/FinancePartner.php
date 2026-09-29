<?php

namespace App\Domain\Finance\Partners;

use App\Domain\Finance\Models\FinanceApplication;

/** A lender that pre-qualifies buyers (TDD M10, phase 3), behind an interface so it can be swapped by market. */
interface FinancePartner
{
    public function code(): string;

    public function name(): string;

    /**
     * Send the consented details; the partner answers straight away or later by webhook.
     *
     * @return array{reference: string, status: string, message?: string|null, approved_amount?: int|null} amounts in kobo
     */
    public function submit(FinanceApplication $application): array;
}
