<?php

namespace App\Filament\Widgets;

use App\Domain\Admin\PlatformMetrics;
use App\Domain\Helpdesk\Enums\TicketStatus;
use App\Domain\Helpdesk\Models\SupportTicket;
use App\Filament\Resources\FraudSignalResource;
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

    protected function getStats(): array
    {
        $q = PlatformMetrics::queue();
        $stat = fn (string $label, int $count, string $url) => Stat::make($label, (string) $count)
            ->color($count > 0 ? 'warning' : 'success')
            ->description($count > 0 ? 'Needs a look' : 'All clear')
            ->url($url);

        return [
            $stat('Lots to verify', $q['verifications'], LotVerificationResource::getUrl()),
            $stat('Flagged listings', $q['signals'], FraudSignalResource::getUrl()),
            $stat('Reports', $q['reports'], ReportResource::getUrl()),
            $stat('Reported reviews', $q['reviews'], ReviewResource::getUrl()),
            $stat('Support tickets', SupportTicket::withoutGlobalScopes()->where('status', TicketStatus::Open)->count(), SupportTicketResource::getUrl()),
        ];
    }
}
