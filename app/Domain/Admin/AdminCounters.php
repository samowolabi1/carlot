<?php

namespace App\Domain\Admin;

use App\Domain\Advertising\Enums\AdStatus;
use App\Domain\Advertising\Models\AdCampaign;
use App\Domain\Finance\Enums\LenderStatus;
use App\Domain\Finance\Models\Lender;
use App\Domain\Helpdesk\Enums\TicketPriority;
use App\Domain\Helpdesk\Enums\TicketStatus;
use App\Domain\Helpdesk\Models\SupportTicket;
use App\Domain\Inventory\Models\VehicleModel;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\Cache;

/**
 * The counts behind the admin menu badges and the dashboard's review queue, worked out once and
 * kept for 30 seconds. Every admin page shows the menu, so these ran ~10 count queries on each click;
 * now it's one cache read. Admin actions that change a queue call forget() so it updates at once.
 */
final class AdminCounters
{
    private const KEY = 'admin:counters';

    private const SECONDS = 30;

    /** @var array<string, int|bool>|null */
    private static ?array $memo = null;

    /**
     * @return array{lots_waiting: int, verifications: int, signals: int, reports: int, reviews: int, adverts: int, tickets: int, urgent_tickets: bool, models: int, lenders_waiting: int}
     */
    public static function all(): array
    {
        /** @var array{lots_waiting: int, verifications: int, signals: int, reports: int, reviews: int, adverts: int, tickets: int, urgent_tickets: bool, models: int, lenders_waiting: int} */
        return self::$memo ??= Cache::remember(self::KEY, self::SECONDS, fn (): array => [
            ...PlatformMetrics::queue(),
            'lots_waiting' => Lot::where('status', LotStatus::Pending)->whereNotNull('submitted_at')->count(),
            'adverts' => AdCampaign::withoutGlobalScopes()->where('status', AdStatus::InReview)->count(),
            'tickets' => SupportTicket::withoutGlobalScopes()->where('status', TicketStatus::Open)->count(),
            'urgent_tickets' => SupportTicket::withoutGlobalScopes()->where('status', TicketStatus::Open)->where('priority', TicketPriority::Urgent)->exists(),
            'models' => VehicleModel::whereNull('approved_at')->count(),
            'lenders_waiting' => Lender::where('status', LenderStatus::Pending)->count(),
        ]);
    }

    /** A menu badge: the count, or nothing when it's zero. */
    public static function badge(string $key): ?string
    {
        $count = (int) (self::all()[$key] ?? 0);

        return $count > 0 ? (string) $count : null;
    }

    public static function forget(): void
    {
        self::$memo = null;
        Cache::forget(self::KEY);
    }
}
