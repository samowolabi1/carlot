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
        // Counts for everyone on the team; links only to the queues their role can open.
        $stat = fn (string $label, int $count, string $url, string $resource) => Stat::make($label, (string) $count)
            ->color($count > 0 ? 'warning' : 'success')
            ->description($count > 0 ? 'Needs a look' : 'All clear')
            ->url($resource::canViewAny() ? $url : null);

        return [
            $stat('Lots to approve', $q['lots_waiting'], LotResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'pending'], 'submitted_at' => ['value' => '1']]]), LotResource::class),
            $stat('Lots to verify', $q['verifications'], LotVerificationResource::getUrl(), LotVerificationResource::class),
            $stat('Flagged listings', $q['signals'], FraudSignalResource::getUrl(), FraudSignalResource::class),
            $stat('Reports', $q['reports'], ReportResource::getUrl(), ReportResource::class),
            $stat('Reported reviews', $q['reviews'], ReviewResource::getUrl(), ReviewResource::class),
            $stat('Adverts to check', $q['adverts'], AdCampaignResource::getUrl(), AdCampaignResource::class),
            $stat('Support tickets', $q['tickets'], SupportTicketResource::getUrl(), SupportTicketResource::class),
            $stat('Lenders to approve', $q['lenders_waiting'], LenderResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'pending']]]), LenderResource::class),
        ];
    }
}
