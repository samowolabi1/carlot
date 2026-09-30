<?php

namespace App\Http\Controllers\Lender;

use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Models\FinanceMessage;
use App\Domain\Finance\Models\Lender;
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
