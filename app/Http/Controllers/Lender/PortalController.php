<?php

namespace App\Http\Controllers\Lender;

use App\Domain\Admin\Impersonation;
use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Enums\LenderRole;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Models\FinanceMessage;
use App\Domain\Finance\Models\Lender;
use App\Domain\Legal\Actions\AcceptTerms;
use App\Domain\Legal\LegalDocuments;
use App\Domain\Support\Money;
use App\Http\Controllers\Controller;
use App\Http\Presenters\FinancePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The lender portal's front door and dashboard. */
class PortalController extends Controller
{
    /** /lender: straight to the lender this person works for, or the sign-up page. */
    public function home(Request $request): RedirectResponse
    {
        $lender = $request->user()->lenders()->orderByRaw("status = 'active' desc")->orderBy('name')->first();

        return $lender ? to_route('lender.dashboard', $lender) : to_route('lenders.join');
    }

    /** One of the lender's admins accepts the current Lender Terms for the lender. */
    public function acceptTerms(Request $request, Lender $lender, AcceptTerms $accept): RedirectResponse
    {
        abort_unless($lender->roleOf($request->user()) === LenderRole::Admin, 403);
        abort_if(Impersonation::active(), 403, 'Only the lender\'s own admin can accept the Lender Terms.');
        $request->validate(['agree' => ['accepted']], ['agree.accepted' => 'Tick the box to accept the Lender Terms.']);
        $accept->forLender($lender, $request->user());

        return back()->with('success', 'Thanks. Your team can now work applications.');
    }

    public function dashboard(Request $request, Lender $lender): Response
    {
        $apps = $lender->applications();
        $counts = (clone $apps)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n);
        $month = now()->startOfMonth();

        return Inertia::render('Lender/Dashboard', [
            'lender' => [
                'status' => $lender->status->value,
                'status_label' => $lender->status->label(),
                'note' => $lender->review_note,
                'product' => $lender->productLine(),
                'integration' => $lender->integration->label(),
                'terms_version' => LegalDocuments::render('lender-terms')['effective'],
                'is_admin' => $lender->roleOf($request->user()) === LenderRole::Admin,
            ],
            'stats' => [
                'new' => $counts[FinanceStatus::Submitted->value] ?? 0,
                'open' => collect(FinanceStatus::open())->sum(fn (FinanceStatus $s) => $counts[$s->value] ?? 0),
                'documents' => $counts[FinanceStatus::DocumentsRequested->value] ?? 0,
                'approved_month' => (clone $apps)->whereIn('status', [FinanceStatus::Approved, FinanceStatus::Disbursed])->where('decided_at', '>=', $month)->count(),
                'disbursed_month' => Money::compact((int) (clone $apps)->where('status', FinanceStatus::Disbursed)->where('disbursed_at', '>=', $month)->sum('disbursed_amount')),
                'mine' => (clone $apps)->whereIn('status', FinanceStatus::open())->where('assigned_to', $request->user()->id)->count(),
            ],
            'recent' => (clone $apps)->with(['vehicle.make', 'vehicle.model', 'lot', 'lender', 'assignee', 'user'])
                ->whereIn('status', FinanceStatus::open())->latest('updated_at')->limit(6)->get()
                ->map(fn (FinanceApplication $a) => FinancePresenter::summary($a, FinanceMessage::LENDER)),
        ])->withViewData(['meta' => ['title' => "{$lender->name} — lender portal", 'robots' => 'noindex']]);
    }
}
