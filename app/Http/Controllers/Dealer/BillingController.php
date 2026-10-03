<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Billing\Actions\ApplyCoupon;
use App\Domain\Billing\Actions\CancelSubscription;
use App\Domain\Billing\Actions\FulfilPayment;
use App\Domain\Billing\Actions\StartCheckout;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\SpotlightPlacement;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Gateways\PaymentGateways;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Spotlight;
use App\Domain\Billing\Support\SpotlightPricing;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotMember;
use App\Domain\Lots\Models\Plan;
use App\Domain\Support\Fields;
use App\Domain\Support\Money;
use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** Billing (design D11, TDD M16): plan, usage, payments; owners change the plan. */
class BillingController extends Controller
{
    public function show(Request $request, Lot $lot): Response
    {
        Gate::authorize('update', $lot);

        $lot->load(['plan', 'subscription.plan']);
        $subscription = $lot->subscription;
        $plan = $lot->plan;
        $tz = $lot->timezone;
        $date = fn ($value) => $value?->copy()->setTimezone($tz)->format('j M Y');

        $live = Vehicle::query()->whereIn('status', VehicleStatus::live())->count();
        $staff = LotMember::query()->where('lot_id', $lot->id)->count();
        $freeLeft = SpotlightPricing::freeLeft($lot);

        return Inertia::render('Dealer/Billing', [
            'subscription' => [
                'plan' => $plan?->name,
                'plan_code' => $plan?->code,
                'price' => $plan && $plan->price > 0 ? Money::format($plan->price, $plan->currency) : null,
                'status' => $subscription?->status->value ?? SubscriptionStatus::Cancelled->value,
                'status_label' => $subscription?->status->label(),
                'trial_ends' => $date($subscription?->trial_ends_at),
                'trial_days_left' => $subscription?->status === SubscriptionStatus::Trialing && $subscription->trial_ends_at ? max(0, (int) ceil(now()->diffInHours($subscription->trial_ends_at, false) / 24)) : null,
                'renews' => $subscription?->cancel_at_period_end ? null : $date($subscription?->current_period_end),
                'ends' => $subscription?->cancel_at_period_end ? $date($subscription->current_period_end) : null,
                'grace_ends' => $date($subscription?->grace_ends_at),
                'card' => $subscription?->card_last4 ? ucfirst((string) $subscription->card_brand).' ending '.$subscription->card_last4 : null,
                // Paystack has a hosted card page; with Flutterwave the owner pays again with the new card.
                'can_update_card' => $subscription?->provider_ref !== null && $subscription->provider !== 'flutterwave',
                'paid_with' => PaymentGateways::PROVIDERS[$subscription->provider ?? ''] ?? null,
                'coupon' => $subscription?->coupon()->value('code'),
            ],
            'usage' => [
                'listings' => ['used' => $live, 'limit' => $plan?->listing_limit],
                'staff' => ['used' => $staff, 'limit' => $plan?->staff_limit],
                'spotlights' => ['used' => max(0, (int) ($plan->free_spotlights ?? 0) - $freeLeft), 'limit' => (int) ($plan->free_spotlights ?? 0)],
            ],
            'plans' => Plan::orderBy('sort')->get()->map(fn (Plan $p) => [
                'code' => $p->code,
                'name' => $p->name,
                'price' => $p->self_serve ? ($p->price > 0 ? Money::format($p->price, $p->currency).'/mo' : '₦0') : 'Talk to us',
                'limits' => ($p->listing_limit ? "Up to {$p->listing_limit} cars" : 'Unlimited cars').' · '.($p->staff_limit ? "{$p->staff_limit} staff" : 'unlimited staff'),
                'blurb' => match ($p->code) {
                    'free' => 'Listing, booking, sharing, seller map, Sales Manager lite',
                    'starter' => '+ share cards, unlimited orders, WhatsApp reminders',
                    'pro' => "+ {$p->free_spotlights} free spotlights a month, leads and offers as they launch",
                    default => '+ multi-branch, custom domain, bulk import, priority support',
                },
                'current' => $p->id === $plan?->id,
                'self_serve' => $p->self_serve,
                'free' => $p->isFree(),
            ]),
            'payments' => Payment::query()->where('lot_id', $lot->id)->whereIn('purpose', PaymentPurpose::billing())->whereIn('status', [PaymentStatus::Success, PaymentStatus::Refunded, PaymentStatus::Failed])
                ->latest()->limit(50)->get()->map(fn (Payment $p) => [
                    'ulid' => $p->ulid,
                    'date' => $date($p->paid_at ?? $p->created_at),
                    'what' => $p->description,
                    'amount' => $p->money(),
                    'status' => $p->status->value,
                    'status_label' => $p->status->label(),
                    'invoice' => $p->status === PaymentStatus::Success || $p->status === PaymentStatus::Refunded ? route('dealer.billing.invoice', [$lot, $p]) : null,
                ]),
            'spotlights' => Spotlight::query()->with(['vehicle.make', 'vehicle.model'])->where('status', 'paid')->where('ends_at', '>', now())->orderBy('ends_at')->get()
                ->map(fn (Spotlight $s) => [
                    'what' => $s->placement === SpotlightPlacement::Car ? ($s->vehicle?->title() ?? 'Car') : 'Featured seller',
                    'placement' => $s->placement->label(),
                    'from' => $date($s->starts_at),
                    'until' => $date($s->ends_at),
                    'free' => $s->free,
                ]),
            'featured' => ['options' => SpotlightPricing::options(SpotlightPlacement::FeaturedLot), 'until' => $date($lot->featured_until?->isFuture() ? $lot->featured_until : null)],
            'can' => [
                'manage' => $request->user()->can('manageBilling', $lot),
                'spotlight' => $request->user()->can('buySpotlight', $lot),
            ],
            'sandbox' => ! PaymentGateways::live(),
            // Where new payments go ("Opening Flutterwave…").
            'checkoutWith' => PaymentGateways::PROVIDERS[PaymentGateways::activeProvider()],
        ]);
    }

    public function checkout(Request $request, Lot $lot, StartCheckout $checkout): SymfonyResponse
    {
        Gate::authorize('manageBilling', $lot);
        $data = $request->validate(['plan' => ['required', 'string', 'exists:plans,code']]);

        return Inertia::location($checkout->run($lot, $request->user(), Plan::where('code', $data['plan'])->firstOrFail()));
    }

    /** Paystack (or the sandbox) sends the owner back here with the reference. */
    public function callback(Request $request, Lot $lot, FulfilPayment $fulfil): RedirectResponse
    {
        $payment = Payment::where('lot_id', $lot->id)->whereIn('purpose', PaymentPurpose::billing())->where('reference', (string) ($request->query('reference') ?? $request->query('trxref') ?? $request->query('tx_ref', '')))->first();

        if ($payment === null) {
            return to_route('dealer.billing', $lot)->with('error', 'We could not find that payment.');
        }

        $payment = $fulfil->run($payment);
        // Car spotlights are bought from Stock; plans and featured-lot slots from Billing.
        $carSpotlight = $payment->purpose === PaymentPurpose::Spotlight
            && Spotlight::withoutGlobalScopes()->whereKey($payment->payable_id)->whereNotNull('vehicle_id')->exists();
        $advert = $payment->purpose === PaymentPurpose::Advert;
        $back = match (true) {
            $carSpotlight => route('dealer.vehicles.index', $lot),
            $advert => route('dealer.ads.index', $lot),
            default => route('dealer.billing', $lot),
        };

        return redirect($back)->with(...match ($payment->status) {
            PaymentStatus::Success => ['success', match (true) {
                $carSpotlight => 'Paid. Your spotlight is live.',
                $advert => 'Paid. CarYard will check your advert (usually within a working day) and it runs from its start date.',
                $payment->purpose === PaymentPurpose::Spotlight => 'Paid. Your business is featured on the home page.',
                default => 'Paid. Your plan is active.',
            }],
            PaymentStatus::Pending => ['success', 'We are waiting for Paystack to confirm the payment.'],
            default => ['error', 'The payment did not go through. You have not been charged.'],
        });
    }

    public function cancel(Request $request, Lot $lot, CancelSubscription $cancel): RedirectResponse
    {
        Gate::authorize('manageBilling', $lot);
        $subscription = $cancel->run($lot, $request->user());

        return back()->with('success', $subscription->cancel_at_period_end
            ? 'Your plan will not renew. It stays active until '.$subscription->current_period_end?->setTimezone($lot->timezone)->format('j M').'.'
            : "You're on the Free plan now.");
    }

    public function coupon(Request $request, Lot $lot, ApplyCoupon $apply): RedirectResponse
    {
        Gate::authorize('manageBilling', $lot);
        $data = $request->validate(['coupon' => Fields::code()]);
        $subscription = $apply->run($lot, $data['coupon']);

        return back()->with('success', "Code applied: {$subscription->plan()->value('name')} free until ".$subscription->trial_ends_at?->setTimezone($lot->timezone)->format('j M Y').'.');
    }

    /** The provider's page for changing the saved card (Paystack has one). */
    public function card(Lot $lot, PaymentGateways $gateways): SymfonyResponse
    {
        Gate::authorize('manageBilling', $lot);
        $subscription = $lot->subscription()->first();
        $link = $subscription?->provider_ref ? $gateways->for($subscription->provider)->manageLink($subscription->provider_ref) : null;

        return $link ? Inertia::location($link) : back()->with('error', 'There is no card to update yet.');
    }

    public function invoice(Lot $lot, Payment $billingPayment): HttpResponse
    {
        Gate::authorize('update', $lot);
        $payment = $billingPayment;
        abort_unless(in_array($payment->status, [PaymentStatus::Success, PaymentStatus::Refunded], true), 404);

        $pdf = Pdf::loadView('pdf.invoice', ['payment' => $payment, 'lot' => $lot, 'paidAt' => ($payment->paid_at ?? $payment->created_at)?->copy()->setTimezone($lot->timezone)->format('j M Y')])
            ->setPaper('a5')->setOption('isFontSubsettingEnabled', true);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="caryard-'.$payment->invoiceNumber().'.pdf"',
        ]);
    }
}
