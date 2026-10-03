<?php

namespace App\Domain\Finance\Lenders;

use App\Domain\Finance\Models\FinanceApplication;

/** The lender works applications in the CarYard lender portal: nothing to send, its team is notified. */
class PortalConnection implements LenderConnection
{
    public function submit(FinanceApplication $application): ?array
    {
        return null;
    }
}
