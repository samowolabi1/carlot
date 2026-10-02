<?php

namespace App\Http\Controllers\Finance;

use App\Domain\Finance\Actions\SendFinanceMessage;
use App\Domain\Finance\Actions\SubmitFinanceApplication;
use App\Domain\Finance\Actions\UpdateFinanceApplication;
use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Enums\LenderStatus;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Models\FinanceMessage;
use App\Domain\Finance\Models\Lender;
use App\Domain\Support\Fields;
use App\Http\Controllers\Controller;
use App\Http\Presenters\DealsPresenter;
use App\Http\Presenters\FinancePresenter;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Car loans for buyers (TDD M10): pick a lender, apply with consent, then follow it, message and upload documents. */
class FinanceController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Finance/Index', [
            'applications' => FinanceApplication::where('user_id', $request->user()->id)->with(['vehicle.make', 'vehicle.model', 'lot', 'lender'])->latest()->get()
                ->map(fn (FinanceApplication $a) => FinancePresenter::summary($a, FinanceMessage::BUYER)),
        ])->withViewData(['meta' => ['title' => 'Car loan applications', 'robots' => 'noindex']]);
    }

    public function create(Request $request, string $car): Response|RedirectResponse
    {
        $vehicle = DealsPresenter::car($car);
        abort_unless($vehicle->price > 0, 404);
        if (! $vehicle->lot->takesFinance()) {
            return redirect($vehicle->publicPath())->with('error', "{$vehicle->lot->name} isn't taking car loan applications right now.");
        }
        $price = intdiv((int) $vehicle->price, 100);
        $budget = $request->user()->budget;

        return Inertia::render('Finance/Apply', [
            'car' => MarketplacePresenter::card($vehicle),
            'price' => $price,
            'lenders' => Lender::where('status', LenderStatus::Active)->orderBy('rate_bp')->orderBy('name')->get()
                ->map(fn (Lender $l) => FinancePresenter::lenderOption($l, $vehicle->lot->state))->values(),
            'defaults' => [
                'deposit' => (int) round($price * (int) config('lotlink.finance.deposit_percent') / 100),
                'tenor_months' => (int) config('lotlink.finance.tenor_months'),
                'monthly_income' => $budget ? intdiv($budget->monthly_income, 100) : null,
                'monthly_commitments' => $budget ? intdiv($budget->monthly_commitments, 100) : null,
                'lender' => $request->query('lender'),
            ],
            'tenors' => Lender::TENORS,
            'employment' => collect(FinanceApplication::EMPLOYMENT)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
        ])->withViewData(['meta' => ['title' => 'Apply for a car loan — '.$vehicle->title(), 'robots' => 'noindex']]);
    }

    public function store(Request $request, string $car, SubmitFinanceApplication $submit): RedirectResponse
    {
        $vehicle = DealsPresenter::car($car);

        foreach (['monthly_income', 'monthly_commitments', 'deposit'] as $key) {
            $request->merge([$key => Fields::cleanMoney($request->input($key))]);
        }

        $data = $request->validate([
            'lender' => ['required', 'string', 'max:80', Rule::exists('lenders', 'slug')->where('status', LenderStatus::Active->value)],
            'monthly_income' => ['required', 'integer', 'min:30000', 'max:1000000000'],
            'monthly_commitments' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'employment' => ['required', Rule::in(array_keys(FinanceApplication::EMPLOYMENT))],
            'employer' => Fields::businessName(required: false),
            'deposit' => Fields::money(min: 0),
            'tenor_months' => ['required', 'integer', Rule::in(Lender::TENORS)],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'Tick the box to agree to share your details.',
            'monthly_income.min' => 'Enter your monthly income in naira.',
            'lender.required' => 'Pick a lender.',
            'lender.exists' => 'That lender isn\'t taking applications. Pick another.',
        ]);

        $lender = Lender::where('slug', $data['lender'])->firstOrFail();
        $application = $submit->run($request->user(), $vehicle, $lender, [...$data, 'consent' => true]);

        return to_route('finance.show', $application)->with($application->status === FinanceStatus::Failed ? 'error' : 'success', match ($application->status) {
            FinanceStatus::PreApproved => 'Good news: you\'re pre-approved in principle. Details below.',
            FinanceStatus::Declined => "Sent. {$lender->name} couldn't pre-approve this one; see below for what may help.",
            FinanceStatus::Failed => (string) $application->partner_message,
            default => "Sent to {$lender->name}. We'll let you know when they reply.",
        });
    }

    public function show(Request $request, FinanceApplication $application): Response
    {
        abort_unless($application->user_id === $request->user()->id, 404);
        $application->forceFill(['buyer_read_at' => now()])->save();

        return Inertia::render('Finance/Show', [
            'application' => FinancePresenter::detail($application, FinanceMessage::BUYER),
            'upload' => ['max_kb' => SendFinanceMessage::MAX_KB, 'mimes' => SendFinanceMessage::MIMES],
        ])->withViewData(['meta' => ['title' => 'Car loan application', 'robots' => 'noindex']]);
    }

    public function message(Request $request, FinanceApplication $application, SendFinanceMessage $send): RedirectResponse
    {
        abort_unless($application->user_id === $request->user()->id, 404);
        $request->validate([
            'body' => Fields::text(2000),
            'file' => ['nullable', 'file', 'max:'.SendFinanceMessage::MAX_KB, 'mimes:'.implode(',', SendFinanceMessage::MIMES)],
        ]);

        $send->run($application, $request->user(), FinanceMessage::BUYER, $request->input('body'), $request->file('file'));

        return back()->with('success', 'Sent to '.$application->lenderName().'.');
    }

    public function withdraw(Request $request, FinanceApplication $application, UpdateFinanceApplication $update): RedirectResponse
    {
        abort_unless($application->user_id === $request->user()->id, 404);
        $update->run($application, FinanceStatus::Withdrawn, [], $request->user());

        return back()->with('success', 'Application withdrawn. '.$application->lenderName().' has been told.');
    }

    /** A document on an application: only the buyer and the lender's team, through a short-lived signed link. */
    public function file(Request $request, FinanceMessage $message): StreamedResponse
    {
        $user = $request->user();
        $application = $message->application;
        // Only the buyer and the lender they chose: never the lot or LotLink staff (Privacy Policy).
        $allowed = $application->user_id === $user->id || ($application->lender && $application->lender->roleOf($user) !== null);
        abort_unless($allowed, 404);

        $disk = Storage::disk(FinanceMessage::DISK);
        abort_unless($message->attachment_path && $disk->exists($message->attachment_path), 404);

        return $disk->response($message->attachment_path, $message->attachment_name ?? basename($message->attachment_path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
