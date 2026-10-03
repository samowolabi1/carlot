<?php

namespace App\Http\Controllers\Lender;

use App\Domain\Accounts\Models\User;
use App\Domain\Finance\Actions\AssignFinanceApplication;
use App\Domain\Finance\Actions\SendFinanceMessage;
use App\Domain\Finance\Actions\UpdateFinanceApplication;
use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Models\FinanceMessage;
use App\Domain\Finance\Models\Lender;
use App\Domain\Support\Fields;
use App\Http\Controllers\Controller;
use App\Http\Presenters\FinancePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The lender's team works applications: review, ask for documents, pre-approve, approve, record payment, decline. */
class ApplicationController extends Controller
{
    public const TABS = ['new', 'open', 'documents', 'approved', 'closed', 'mine', 'all'];

    public function index(Request $request, Lender $lender): Response
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'open';
        $q = Str::of((string) $request->query('q'))->squish()->limit(60, '')->replace(['%', '_'], '')->value() ?: null;

        $query = $lender->applications()->with(['vehicle.make', 'vehicle.model', 'lot', 'lender', 'assignee', 'user'])->latest('updated_at');
        match ($tab) {
            'new' => $query->where('status', FinanceStatus::Submitted),
            'open' => $query->whereIn('status', FinanceStatus::open()),
            'documents' => $query->where('status', FinanceStatus::DocumentsRequested),
            'approved' => $query->whereIn('status', [FinanceStatus::PreApproved, FinanceStatus::Approved, FinanceStatus::Disbursed]),
            'closed' => $query->whereIn('status', [FinanceStatus::Declined, FinanceStatus::Withdrawn, FinanceStatus::Disbursed, FinanceStatus::Failed]),
            'mine' => $query->where('assigned_to', $request->user()->id)->whereIn('status', FinanceStatus::open()),
            default => null,
        };
        if ($q !== null) {
            // Names are encrypted with the application, so search the buyer's account, the car, the seller and references.
            $query->where(fn ($w) => $w->where('ulid', 'like', strtolower($q).'%')->orWhere('external_ref', 'like', "%{$q}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%"))
                ->orWhereHas('lot', fn ($l) => $l->withoutGlobalScopes()->where('name', 'like', "%{$q}%"))
                ->orWhereHas('vehicle', fn ($v) => $v->withoutGlobalScopes()->whereHas('make', fn ($m) => $m->where('name', 'like', "%{$q}%"))
                    ->orWhereHas('model', fn ($m) => $m->where('name', 'like', "%{$q}%"))));
        }

        return Inertia::render('Lender/Applications/Index', [
            'tab' => $tab,
            'q' => $q ?? '',
            'applications' => fn () => $query->paginate(20)->withQueryString()
                ->through(fn (FinanceApplication $a) => FinancePresenter::summary($a, FinanceMessage::LENDER)),
        ])->withViewData(['meta' => ['title' => 'Applications — '.$lender->name, 'robots' => 'noindex']]);
    }

    public function show(Request $request, Lender $lender, FinanceApplication $application): Response
    {
        $application->forceFill(['lender_read_at' => now()])->save();

        return Inertia::render('Lender/Applications/Show', [
            'application' => FinancePresenter::detail($application, FinanceMessage::LENDER),
            'actions' => collect(['review', 'documents', 'pre_approve', 'approve', 'disburse', 'decline'])
                ->filter(fn (string $a) => $lender->isActive() && in_array($application->status, FinanceStatus::allowedFrom($a), true))->values(),
            'team' => $lender->members()->orderBy('name')->get(['users.id', 'users.ulid', 'users.name'])->map(fn (User $u) => ['ulid' => $u->ulid, 'name' => $u->name])->values(),
            'assigned' => $application->assignee?->ulid,
            'tenors' => $lender->tenors,
            'rate' => $lender->rate_bp / 100,
            'next_steps' => $application->next_steps ?? $lender->next_steps ?? '',
            'upload' => ['max_kb' => SendFinanceMessage::MAX_KB, 'mimes' => SendFinanceMessage::MIMES],
        ])->withViewData(['meta' => ['title' => 'Application — '.$lender->name, 'robots' => 'noindex']]);
    }

    public function status(Request $request, Lender $lender, FinanceApplication $application, UpdateFinanceApplication $update): RedirectResponse
    {
        abort_unless($lender->isActive(), 403, 'Your lender account is not active.');
        foreach (['approved_amount', 'disbursed_amount'] as $key) {
            $request->merge([$key => Fields::cleanMoney($request->input($key))]);
        }
        $action = (string) $request->input('action');
        $data = $request->validate([
            'action' => ['required', Rule::in(['review', 'documents', 'pre_approve', 'approve', 'disburse', 'decline'])],
            'message' => ['nullable', ...array_slice(Fields::text(1000), 1), Rule::requiredIf(in_array($action, ['documents', 'decline'], true))],
            'approved_amount' => [Rule::requiredIf(in_array($action, ['pre_approve', 'approve'], true)), 'nullable', 'integer', 'min:1', 'max:'.intdiv($application->amount, 100)],
            'rate' => [Rule::requiredIf($action === 'approve'), 'nullable', 'numeric', 'min:1', 'max:99', 'decimal:0,2'],
            'tenor_months' => [Rule::requiredIf($action === 'approve'), 'nullable', 'integer', Rule::in($lender->tenors)],
            'disbursed_amount' => [Rule::requiredIf($action === 'disburse'), 'nullable', 'integer', 'min:1', 'max:'.Fields::MONEY_MAX],
            'disbursed_reference' => [...Fields::reference(required: $action === 'disburse')],
            'next_steps' => Fields::text(1000),
        ], [
            'message.required' => $action === 'documents' ? 'Say which documents you need.' : 'Tell the buyer why, and what might help.',
            'approved_amount.max' => 'You can\'t approve more than the buyer asked for ('.$application->money().').',
        ]);

        $to = match ($action) {
            'review' => FinanceStatus::Received,
            'documents' => FinanceStatus::DocumentsRequested,
            'pre_approve' => FinanceStatus::PreApproved,
            'approve' => FinanceStatus::Approved,
            'disburse' => FinanceStatus::Disbursed,
            default => FinanceStatus::Declined,
        };

        $update->run($application, $to, [
            'message' => $data['message'] ?? null,
            'next_steps' => $data['next_steps'] ?? null,
            'approved_amount' => isset($data['approved_amount']) ? (int) $data['approved_amount'] * 100 : null,
            'offer_rate_bp' => isset($data['rate']) ? (int) round((float) $data['rate'] * 100) : null,
            'offer_tenor_months' => isset($data['tenor_months']) ? (int) $data['tenor_months'] : null,
            'disbursed_amount' => isset($data['disbursed_amount']) ? (int) $data['disbursed_amount'] * 100 : null,
            'disbursed_reference' => $data['disbursed_reference'] ?? null,
        ], $request->user());

        if ($application->assigned_to === null) {
            $application->update(['assigned_to' => $request->user()->id]);
        }

        return back()->with('success', "Marked \"{$to->label()}\". The buyer has been told.");
    }

    public function assign(Request $request, Lender $lender, FinanceApplication $application, AssignFinanceApplication $assign): RedirectResponse
    {
        $data = $request->validate(['assigned_to' => Fields::ulid(required: false)]);
        $officer = filled($data['assigned_to'] ?? null) ? User::where('ulid', strtolower($data['assigned_to']))->firstOrFail() : null;
        $assign->run($application, $officer, $request->user());

        return back()->with('success', $officer ? "Given to {$officer->name}." : 'Back with the whole team.');
    }

    public function message(Request $request, Lender $lender, FinanceApplication $application, SendFinanceMessage $send): RedirectResponse
    {
        $request->validate([
            'body' => Fields::text(2000),
            'file' => ['nullable', 'file', 'max:'.SendFinanceMessage::MAX_KB, 'mimes:'.implode(',', SendFinanceMessage::MIMES)],
        ]);
        $send->run($application, $request->user(), FinanceMessage::LENDER, $request->input('body'), $request->file('file'));

        return back()->with('success', 'Sent to the buyer.');
    }
}
