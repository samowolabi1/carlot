<?php

namespace App\Http\Controllers\Dealer\Manager;

use App\Domain\LotManager\Support\ManagerReports;
use App\Domain\LotManager\Support\ReportExport;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /reports?type=…&period=… (TDD M19): sales, balances and walk-ins for owners and managers;
 * profit, the staff report and Excel export on plans with car costs (Pro).
 */
class ReportController extends Controller
{
    public function __invoke(Request $request, Lot $lot): Response|BinaryFileResponse
    {
        Gate::authorize('viewReports', $lot);

        $pro = $request->user()->can('viewCosts', $lot);
        $type = array_key_exists((string) $request->query('type'), ManagerReports::TYPES) ? (string) $request->query('type') : 'sales';
        $period = array_key_exists((string) $request->query('period'), ManagerReports::PERIODS) ? (string) $request->query('period') : 'year';
        abort_if($type === 'staff' && ! $pro, 403, 'The staff report comes with the Pro plan.');

        $report = match ($type) {
            'balances' => ManagerReports::balances($lot),
            'walk-ins' => ManagerReports::walkIns($lot, $period),
            'staff' => ManagerReports::staff($lot, $period),
            default => ManagerReports::sales($lot, $period, $pro),
        };

        if ($request->query('export') === 'xlsx') {
            abort_unless($pro, 403, 'Excel export comes with the Pro plan.');

            return Excel::download(new ReportExport($report), "lotlink-{$lot->slug}-{$type}-".now($lot->timezone)->format('Y-m-d').'.xlsx');
        }

        return Inertia::render('Dealer/Manager/Reports', [
            'type' => $type,
            'period' => $period,
            'types' => collect(ManagerReports::TYPES)->map(fn ($label, $key) => ['value' => $key, 'label' => $label, 'locked' => $key === 'staff' && ! $pro])->values(),
            'periods' => collect(ManagerReports::PERIODS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'report' => $report,
            'pro' => $pro,
            'hasPeriod' => $type !== 'balances',
        ]);
    }
}
