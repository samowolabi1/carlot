<?php

namespace App\Filament\Widgets;

use App\Domain\Admin\AdminCounters;
use App\Filament\Resources\AdCampaignResource;
use App\Filament\Resources\FraudSignalResource;
use App\Filament\Resources\LenderResource;
use App\Filament\Resources\LotResource;
use App\Filament\Resources\LotVerificationResource;
use App\Filament\Resources\ReportResource;
use App\Filament\Resources\ReviewResource;
use App\Filament\Resources\SupportTicketResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** The review queue (design A1): signals flag items for a person to check; nothing is blocked automatically. */
class ReviewQueue extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    protected ?string $heading = 'Review queue';

    /** Eight counts: four across. */
    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $q = AdminCounters::all();
        $stat = fn (string $label, int $count, string $url) => Stat::make($label, (string) $count)
            ->color($count > 0 ? 'warning' : 'success')
            ->description($count > 0 ? 'Needs a look' : 'All clear')
            ->url($url);

        return [
            $stat('Lots to approve', $q['lots_waiting'], LotResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'pending'], 'submitted_at' => ['value' => '1']]])),
            $stat('Lots to verify', $q['verifications'], LotVerificationResource::getUrl()),
            $stat('Flagged listings', $q['signals'], FraudSignalResource::getUrl()),
            $stat('Reports', $q['reports'], ReportResource::getUrl()),
            $stat('Reported reviews', $q['reviews'], ReviewResource::getUrl()),
            $stat('Adverts to check', $q['adverts'], AdCampaignResource::getUrl()),
            $stat('Support tickets', $q['tickets'], SupportTicketResource::getUrl()),
            $stat('Lenders to approve', $q['lenders_waiting'], LenderResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'pending']]])),
        ];
    }
}
