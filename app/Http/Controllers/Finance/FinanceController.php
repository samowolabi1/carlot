<?php

namespace App\Http\Controllers\Finance;

use App\Domain\Finance\Actions\SubmitFinanceApplication;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Partners\FinancePartner;
use App\Http\Controllers\Controller;
use App\Http\Presenters\DealsPresenter;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Finance pre-qualification (TDD M10): the buyer's consented hand-off to a lending partner. */
class FinanceController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Finance/Index', [
            'applications' => FinanceApplication::where('user_id', $request->user()->id)->with(['vehicle.make', 'vehicle.model', 'lot'])->latest()->get()
                ->map(fn (FinanceApplication $a) => [
                    'ulid' => $a->ulid,
                    'car' => $a->vehicle?->title(),
                    'car_url' => $a->vehicle ? $a->vehicle->publicPath() : null,
                    'lot' => $a->lot?->name,
                    'amount' => $a->money(),
                    'approved' => $a->approved_amount ? $a->money($a->approved_amount) : null,
                    'months' => $a->tenor_months,
                    'status' => $a->status,
                    'status_label' => $a->statusLabel(),
                    'message' => $a->partner_message,
                    'reference' => $a->external_ref,
                    'date' => $a->created_at->copy()->setTimezone('Africa/Lagos')->format('j M Y'),
                ]),
            'partner' => config('lotlink.finance_partner.name'),
        ])->withViewData(['meta' => ['title' => 'Finance applications', 'robots' => 'noindex']]);
    }

    public function create(Request $request, string $car, FinancePartner $partner): Response
    {
        $vehicle = DealsPresenter::car($car);
        abort_unless($vehicle->price > 0, 404);
        $price = intdiv((int) $vehicle->price, 100);
        $budget = $request->user()->budget;

        return Inertia::render('Finance/Apply', [
            'car' => MarketplacePresenter::card($vehicle),
            'price' => $price,
            'partner' => $partner->name(),
            'defaults' => [
                'deposit' => (int) round($price * (int) config('lotlink.finance.deposit_percent') / 100),
                'tenor_months' => (int) config('lotlink.finance.tenor_months'),
                'rate' => (float) config('lotlink.finance.interest_rate'),
                'monthly_income' => $budget ? intdiv($budget->monthly_income, 100) : null,
                'monthly_commitments' => $budget ? intdiv($budget->monthly_commitments, 100) : null,
            ],
            'tenors' => [12, 24, 36, 48],
            'employment' => collect(FinanceApplication::EMPLOYMENT)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
        ])->withViewData(['meta' => ['title' => 'Check if you qualify — '.$vehicle->title(), 'robots' => 'noindex']]);
    }

    public function store(Request $request, string $car, SubmitFinanceApplication $submit): RedirectResponse
    {
        $vehicle = DealsPresenter::car($car);

        foreach (['monthly_income', 'monthly_commitments', 'deposit'] as $key) {
            $request->merge([$key => (int) preg_replace('/[^\d]/', '', (string) $request->input($key))]);
        }

        $data = $request->validate([
            'monthly_income' => ['required', 'integer', 'min:30000', 'max:1000000000'],
            'monthly_commitments' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'employment' => ['required', Rule::in(array_keys(FinanceApplication::EMPLOYMENT))],
            'employer' => ['nullable', 'string', 'max:120'],
            'deposit' => ['required', 'integer', 'min:0'],
            'tenor_months' => ['required', 'integer', Rule::in([12, 24, 36, 48])],
            'consent' => ['accepted'],
        ], ['consent.accepted' => 'Tick the box to agree to share your details.', 'monthly_income.min' => 'Enter your monthly income in naira.']);

        $application = $submit->run($request->user(), $vehicle, [...$data, 'consent' => true]);

        return to_route('finance.index')->with($application->status === 'failed' ? 'error' : 'success', match ($application->status) {
            'pre_approved' => 'Good news: you\'re pre-approved in principle. Details below.',
            'declined' => 'Sent. The partner couldn\'t pre-approve this one; see below for what may help.',
            'failed' => (string) $application->partner_message,
            default => 'Sent. We\'ll let you know when the partner replies.',
        });
    }
}
