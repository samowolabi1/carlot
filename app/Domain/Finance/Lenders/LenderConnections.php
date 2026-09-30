<?php

namespace App\Domain\Finance\Lenders;

use App\Domain\Finance\Enums\LenderIntegration;
use App\Domain\Finance\Models\Lender;

/** Picks how to reach a lender from its settings. Tests swap this class to stand in for a lender. */
class LenderConnections
{
    public function for(Lender $lender): LenderConnection
    {
        return match ($lender->integration) {
            LenderIntegration::Api => new HttpConnection($lender),
            LenderIntegration::Demo => new DemoConnection,
            LenderIntegration::Portal => new PortalConnection,
        };
    }
}
