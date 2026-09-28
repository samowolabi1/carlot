<?php

namespace App\Domain\LotManager\Support;

use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Models\VehicleCost;

/**
 * Profit per car = agreed price − discount − the car's costs (TDD M19). Owner and manager
 * only (LotPolicy::viewCosts); never on customer pages, receipts or API resources.
 */
final class Profit
{
    public static function costs(int $vehicleId): int
    {
        return (int) VehicleCost::withoutGlobalScopes()->where('vehicle_id', $vehicleId)->sum('amount');
    }

    /** @return array{revenue: int, costs: int, profit: int, margin: ?float} */
    public static function forOrder(SalesOrder $order): array
    {
        $revenue = $order->agreed_price - $order->discount;
        $costs = self::costs($order->vehicle_id);

        return [
            'revenue' => $revenue,
            'costs' => $costs,
            'profit' => $revenue - $costs,
            'margin' => $revenue > 0 ? round(($revenue - $costs) / $revenue * 100, 1) : null,
        ];
    }
}
