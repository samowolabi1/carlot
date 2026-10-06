<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Analytics\Support\DealerAnalytics;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Models\Lead;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Input;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** GET /analytics (TDD M15, design D8): charts for owners and managers, and CSV exports on Pro. */
class AnalyticsController extends Controller
{
    public function __invoke(Request $request, Lot $lot): Response|StreamedResponse
    {
        Gate::authorize('viewReports', $lot);

        $allowed = $lot->planAllows('analytics');
        $full = $lot->planAllows('analytics_full');
        $period = array_key_exists(Input::query($request, 'period'), DealerAnalytics::PERIODS) ? Input::query($request, 'period') : '30d';

        if ($export = $request->query('export')) {
            abort_unless($full && in_array($export, ['stock', 'leads', 'sales'], true), 403, 'CSV export comes with the Pro plan.');

            return $this->csv($lot, (string) $export, $period);
        }

        return Inertia::render('Dealer/Analytics', [
            'allowed' => $allowed,
            'full' => $full,
            'period' => $period,
            'periods' => [['value' => '7d', 'label' => 'Last 7 days'], ['value' => '30d', 'label' => 'Last 30 days'], ['value' => '90d', 'label' => 'Last 90 days']],
            'data' => $allowed ? DealerAnalytics::page($lot, $period, $full) : null,
        ]);
    }

    private function csv(Lot $lot, string $type, string $period): StreamedResponse
    {
        [$from, $to] = DealerAnalytics::range($lot, $period);
        $tz = $lot->timezone;
        [$utcFrom, $utcTo] = [$from->startOfDay()->utc(), $to->endOfDay()->utc()];

        [$headings, $rows] = match ($type) {
            'stock' => [
                ['Car', 'Status', 'Price (NGN)', 'Mileage (km)', 'Listed', 'Days in stock', 'VIN'],
                Vehicle::query()->with(['make', 'model'])->where('status', '!=', 'draft')->orderByDesc('listed_at')->get()
                    ->map(fn (Vehicle $v) => [$v->title(), $v->status->label(), $v->price !== null ? intdiv($v->price, 100) : '', $v->mileage_km, $v->listed_at?->copy()->setTimezone($tz)->format('Y-m-d'), $v->daysListed(), $v->vin]),
            ],
            'leads' => [
                ['Created', 'Customer', 'Car', 'Source', 'Stage', 'Assigned to', 'First reply', 'Lost reason'],
                Lead::query()->with(['customer', 'lotCustomer', 'vehicle.make', 'vehicle.model', 'assignee'])->whereBetween('created_at', [$utcFrom, $utcTo])->latest()->get()
                    ->map(fn (Lead $l) => [
                        $l->created_at?->copy()->setTimezone($tz)->format('Y-m-d H:i'), $l->customer->name ?? $l->lotCustomer->name ?? '', $l->vehicle?->title(),
                        $l->source->label(), $l->stage->label(), $l->assignee?->name, $l->first_response_at?->copy()->setTimezone($tz)->format('Y-m-d H:i'), $l->lost_reason,
                    ]),
            ],
            default => [
                ['Order', 'Created', 'Delivered', 'Customer', 'Car', 'Agreed (NGN)', 'Discount (NGN)', 'Paid (NGN)', 'Balance (NGN)', 'Status', 'Sold by'],
                SalesOrder::query()->with(['customer', 'vehicle.make', 'vehicle.model', 'staff'])->where('status', '!=', OrderStatus::Cancelled)
                    ->whereBetween('created_at', [$utcFrom, $utcTo])->latest()->get()
                    ->map(fn (SalesOrder $o) => [
                        $o->order_no, $o->created_at?->copy()->setTimezone($tz)->format('Y-m-d'), $o->delivered_at?->copy()->setTimezone($tz)->format('Y-m-d'),
                        $o->customer->name ?? '', $o->vehicle?->title(), intdiv($o->agreed_price, 100), intdiv($o->discount, 100), intdiv($o->total_paid, 100), intdiv($o->balance, 100),
                        $o->status->label(), $o->staff?->name,
                    ]),
            ],
        };

        return response()->streamDownload(function () use ($headings, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads ₦ and names correctly
            fputcsv($out, $headings);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => $v ?? '', $row));
            }
            fclose($out);
        }, "caryard-{$lot->slug}-{$type}-".now($tz)->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
