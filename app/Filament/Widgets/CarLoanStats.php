<?php

namespace App\Filament\Widgets;

use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/** Car loans at a glance (last 30 days): applications, how many were pre-approved or approved, and money lent and paid to lots. */
class CarLoanStats extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $s = Cache::remember('admin:car-loan-stats', 60, function (): array {
            $recent = FinanceApplication::query()->where('created_at', '>=', now()->subDays(30));
            $sent = (clone $recent)->where('status', '!=', FinanceStatus::Failed)->count();
            $yes = (clone $recent)->whereIn('status', [FinanceStatus::PreApproved, FinanceStatus::Approved, FinanceStatus::Disbursed])->count();
            $decided = $yes + (clone $recent)->where('status', FinanceStatus::Declined)->count();

            return [
                'sent' => $sent,
                'open' => FinanceApplication::query()->whereIn('status', FinanceStatus::open())->count(),
                'rate' => $decided > 0 ? (int) round($yes / $decided * 100) : null,
                'approved' => (int) (clone $recent)->whereIn('status', [FinanceStatus::Approved, FinanceStatus::Disbursed])->sum('approved_amount'),
                'disbursed' => (int) FinanceApplication::query()->where('status', FinanceStatus::Disbursed)->where('disbursed_at', '>=', now()->subDays(30))->sum('disbursed_amount'),
            ];
        });

        return [
            Stat::make('Applications (30 days)', (string) $s['sent'])->description("{$s['open']} open now"),
            Stat::make('Said yes', $s['rate'] !== null ? "{$s['rate']}%" : '—')->description('Pre-approved or approved, of those decided'),
            Stat::make('Approved (30 days)', Money::compact($s['approved'])),
            Stat::make('Paid to sellers (30 days)', Money::compact($s['disbursed'])),
        ];
    }
}
