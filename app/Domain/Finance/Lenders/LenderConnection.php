<?php

namespace App\Domain\Finance\Lenders;

use App\Domain\Finance\Models\FinanceApplication;

/** How an application reaches a lender: the lender portal, the lender's own API, or the demo lender. */
interface LenderConnection
{
    /**
     * Hand over the consented application. Null: it waits for the lender's team in the portal. Otherwise the
     * lender's first answer (later ones arrive by webhook).
     *
     * @return array{reference: string, status: string, message?: string|null, approved_amount?: int|null}|null amounts in kobo
     */
    public function submit(FinanceApplication $application): ?array;
}
