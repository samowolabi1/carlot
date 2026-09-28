<?php

namespace App\Domain\LotManager\Support;

use App\Domain\LotManager\Enums\DocumentStatus;
use App\Domain\LotManager\Enums\DocumentType;
use App\Domain\LotManager\Models\OrderDocument;
use App\Domain\LotManager\Models\SalesOrder;

/** The papers and handover checklist every order carries (TDD M19). */
final class OrderDocuments
{
    /** Creates the standard checklist the first time an order needs it. */
    public static function ensure(SalesOrder $order): void
    {
        if (OrderDocument::withoutGlobalScopes()->where('sales_order_id', $order->id)->exists()) {
            return;
        }

        foreach (DocumentType::defaults() as $type => $mandatory) {
            OrderDocument::withoutGlobalScopes()->create([
                'lot_id' => $order->lot_id,
                'sales_order_id' => $order->id,
                'type' => $type,
                'mandatory' => $mandatory,
                'status' => DocumentStatus::Pending,
            ]);
        }
    }

    /**
     * Names of required papers not in yet; "papers ready" waits for these.
     *
     * @return list<string>
     */
    public static function missing(SalesOrder $order): array
    {
        self::ensure($order);

        return OrderDocument::withoutGlobalScopes()->where('sales_order_id', $order->id)->where('mandatory', true)
            ->where('status', DocumentStatus::Pending)->get()->map(fn (OrderDocument $d) => $d->name())->values()->all();
    }
}
