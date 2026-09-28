<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Leads\Models\Lead;
use App\Domain\LotManager\Models\SalesOrder;

class CloseLeadsForSale
{
    /**
     * When a car is handed over (TDD M13): the buyer's lead is won, and everyone else's
     * open lead on that car is lost with the reason "sold".
     */
    public function run(SalesOrder $order): void
    {
        $leads = Lead::withoutGlobalScopes()
            ->where('lot_id', $order->lot_id)
            ->where('vehicle_id', $order->vehicle_id)
            ->whereNotIn('stage', [LeadStage::Won, LeadStage::Lost])
            ->get();

        foreach ($leads as $lead) {
            $won = $lead->lot_customer_id === $order->lot_customer_id;
            $lead->forceFill([
                'stage' => $won ? LeadStage::Won : LeadStage::Lost,
                'lost_reason' => $won ? null : 'Car sold',
                'closed_at' => now(),
            ])->save();
        }
    }
}
