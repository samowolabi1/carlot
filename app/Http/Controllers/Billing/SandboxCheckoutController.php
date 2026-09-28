<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Billing\Models\Payment;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The sandbox's stand-in for Paystack's checkout page (local development only). */
class SandboxCheckoutController extends Controller
{
    public function show(Payment $payment, PaymentGateway $gateway): View
    {
        abort_unless($gateway->name() === 'sandbox' && $payment->status === PaymentStatus::Pending, 404);

        return view('billing.sandbox', ['payment' => $payment]);
    }

    public function complete(Request $request, Payment $payment, PaymentGateway $gateway): RedirectResponse
    {
        abort_unless($gateway->name() === 'sandbox' && $payment->status === PaymentStatus::Pending, 404);
        $result = $request->validate(['result' => ['required', 'in:paid,declined']])['result'];

        $payment->forceFill(['meta' => [...($payment->meta ?? []), 'sandbox' => $result]])->save();
        $callback = (string) ($payment->meta['callback'] ?? route('home'));

        return redirect($callback.(str_contains($callback, '?') ? '&' : '?').http_build_query(['reference' => $payment->reference]));
    }
}
