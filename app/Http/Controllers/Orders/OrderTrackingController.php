<?php

namespace App\Http\Controllers\Orders;

use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Support\OrderLinks;
use App\Domain\LotManager\Support\ReceiptPdf;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\PhoneNumber;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /o/{ulid}: the customer's view of their order from the signed link on every receipt
 * and message (TDD M19: Growth hooks). No sign-in; no staff or cost details.
 */
class OrderTrackingController extends Controller
{
    public function show(SalesOrder $order): Response
    {
        $order->load(['vehicle.make', 'vehicle.model', 'vehicle.cover', 'customer', 'payments']);
        $lot = Lot::withTrashed()->findOrFail($order->lot_id);
        $tz = $lot->timezone;

        $steps = [OrderStatus::Draft, OrderStatus::DepositPaid, OrderStatus::FullyPaid, OrderStatus::PapersReady, OrderStatus::Delivered];

        return Inertia::render('Orders/Track', [
            'order' => [
                'order_no' => $order->order_no,
                'status' => $order->status->value,
                'status_label' => $order->status === OrderStatus::Draft ? 'Order started' : $order->status->label(),
                'cancelled' => $order->status === OrderStatus::Cancelled,
                'customer' => $order->customer ? explode(' ', $order->customer->name)[0] : null,
                'car' => $order->vehicle?->title(),
                'photo' => collect($order->vehicle?->cover?->urls() ?? [])->first(),
                'total' => $order->money($order->total()),
                'paid' => $order->money(max(0, $order->total_paid)),
                'balance' => $order->money(max(0, $order->balance)),
                'fully_paid' => $order->balance <= 0,
                'progress' => $order->total() > 0 ? min(100, (int) round(max(0, $order->total_paid) / $order->total() * 100)) : 0,
                'delivered' => $order->delivered_at?->copy()->setTimezone($tz)->format('j M Y'),
            ],
            'steps' => collect($steps)->map(fn (OrderStatus $s) => [
                'label' => $s === OrderStatus::Draft ? 'Order started' : $s->label(),
                'done' => $order->status !== OrderStatus::Cancelled && $order->status->rank() >= $s->rank(),
            ]),
            'payments' => $order->payments->whereNull('voided_at')->values()->map(fn (OrderPayment $p) => [
                'amount' => $order->money(abs($p->amount)),
                'refund' => $p->amount < 0,
                'method' => $p->method->label(),
                'when' => $p->paid_at->copy()->setTimezone($tz)->format('j M Y'),
                'receipt_no' => $p->receipt_no,
                'receipt_url' => OrderLinks::receipt($p, $order),
            ]),
            'lot' => [
                'name' => $lot->name,
                'initials' => $lot->initials(),
                'logo_url' => $lot->logo_url,
                'phone' => $lot->phone,
                'phone_display' => $lot->phone ? PhoneNumber::display($lot->phone) : null,
                'whatsapp' => $lot->whatsapp ? ltrim($lot->whatsapp, '+') : null,
                'address' => collect([$lot->address, $lot->city])->filter()->implode(', '),
                'directions' => $lot->directionsUrl(),
                'url' => route('lots.show', $lot->slug),
            ],
            'poweredBy' => OrderLinks::poweredBy('order_tracking', $lot->slug),
        ]);
    }

    public function receipt(SalesOrder $order, OrderPayment $payment): HttpResponse
    {
        abort_if($payment->isVoid(), 404);

        return response(ReceiptPdf::render($payment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="receipt-'.$payment->receipt_no.'.pdf"',
        ]);
    }
}
