<?php

namespace App\Http\Controllers\Dealer\Manager;

use App\Domain\LotManager\Actions\RecordPayment;
use App\Domain\LotManager\Actions\VoidPayment;
use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Support\ReceiptPdf;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\PaymentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    public function store(PaymentRequest $request, Lot $lot, SalesOrder $order, RecordPayment $record): RedirectResponse
    {
        $payment = $record->run($order, $request->user(), $request->action());
        $customer = $order->customer()->first();

        return back()->with('success', "Payment saved. Receipt {$payment->receipt_no}".(
            $customer?->consent_whatsapp ? ' sent to the customer.' : ' is ready to print or share.'
        ));
    }

    public function void(Request $request, Lot $lot, OrderPayment $payment, VoidPayment $void): RedirectResponse
    {
        $order = SalesOrder::query()->findOrFail($payment->sales_order_id);
        Gate::authorize('voidPayment', $order);

        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $void->run($payment, $request->user(), $data['reason']);

        return back()->with('success', "Receipt {$payment->receipt_no} voided.");
    }

    public function receipt(Lot $lot, SalesOrder $order, OrderPayment $payment): Response
    {
        Gate::authorize('view', $order);

        return response(ReceiptPdf::render($payment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="receipt-'.$payment->receipt_no.'.pdf"',
        ]);
    }
}
