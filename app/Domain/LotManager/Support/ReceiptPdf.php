<?php

namespace App\Domain\LotManager\Support;

use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\PhoneNumber;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * A payment receipt as a PDF, numbered per lot ({LOTCODE}-{YYYY}-{00001}). Rendered on
 * demand so a voided payment's receipt always shows VOID.
 */
final class ReceiptPdf
{
    public static function render(OrderPayment $payment): string
    {
        $order = SalesOrder::withoutGlobalScopes()->with(['vehicle.make', 'vehicle.model', 'customer', 'staff'])->findOrFail($payment->sales_order_id);
        $lot = Lot::withTrashed()->findOrFail($order->lot_id);
        $tz = $lot->timezone;

        // Totals as they stood when this payment was made.
        $paidToDate = (int) $order->payments()->whereNull('voided_at')
            ->where(fn ($q) => $q->where('paid_at', '<', $payment->paid_at)->orWhere(fn ($q) => $q->where('paid_at', $payment->paid_at)->where('id', '<=', $payment->id)))
            ->sum('amount');

        return Pdf::loadView('pdf.receipt', [
            'lot' => $lot,
            'lotPhone' => $lot->phone ? PhoneNumber::display($lot->phone) : null,
            'order' => $order,
            'payment' => $payment,
            'customerPhone' => PhoneNumber::display($order->customer->phone),
            'car' => $order->vehicle?->title(),
            'receiver' => $payment->receiver()->value('name'),
            'vin' => $order->vehicle?->vin,
            'paidAt' => $payment->paid_at->copy()->setTimezone($tz)->format('j M Y, H:i'),
            'paidToDate' => $paidToDate,
            'balance' => $order->total() - ($payment->isVoid() ? $order->total_paid : $paidToDate),
            'trackUrl' => OrderLinks::track($order),
            'poweredBy' => OrderLinks::poweredBy('receipt', $lot->slug),
        ])->setPaper('a5')->setOption('isFontSubsettingEnabled', true)->output();
    }
}
