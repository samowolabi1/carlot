<?php

namespace App\Domain\Admin;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Domain\Trust\Enums\ReportStatus;
use App\Domain\Trust\Enums\SignalStatus;
use App\Domain\Trust\Enums\VerificationStatus;
use App\Domain\Trust\Models\FraudSignal;
use App\Domain\Trust\Models\LotVerification;
use App\Domain\Trust\Models\Report;
use App\Domain\Trust\Models\Review;

/**
 * Platform metrics for the admin dashboard (TDD M17): active lots, listings, new users,
 * bookings, value of recorded sales, MRR and churn. Money is in kobo.
 */
final class PlatformMetrics
{
    public const DAYS = 30;

    /** @return array{active_lots: int, live_listings: int, new_users: int, bookings: int, sales_value: int, sales_count: int, mrr: int, churn: float|null} */
    public static function summary(): array
    {
        $since = now()->subDays(self::DAYS);

        $paying = Subscription::query()->where('subscriptions.status', SubscriptionStatus::Active)
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')->where('plans.price', '>', 0);
        $mrr = (int) (clone $paying)->sum('plans.price');
        $active = (clone $paying)->count();
        // Paid subscriptions that ended in the period, against those paying now plus those lost.
        $lost = Subscription::query()->where('subscriptions.status', SubscriptionStatus::Cancelled)->where('subscriptions.updated_at', '>=', $since)
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')->where('plans.price', '>', 0)->count();

        $sales = SalesOrder::withoutGlobalScopes()->where('status', OrderStatus::Delivered)->where('delivered_at', '>=', $since);

        return [
            'active_lots' => Lot::where('status', LotStatus::Active)->count(),
            'live_listings' => Vehicle::query()->marketplace()->count(),
            'new_users' => User::where('role', '!=', UserRole::Admin)->where('created_at', '>=', $since)->count(),
            'bookings' => Appointment::withoutGlobalScopes()->where('created_at', '>=', $since)->count(),
            'sales_value' => (int) (clone $sales)->sum('agreed_price'),
            'sales_count' => (clone $sales)->count(),
            'mrr' => $mrr,
            'churn' => $active + $lost > 0 ? round($lost / ($active + $lost) * 100, 1) : null,
        ];
    }

    /** What waits in the review queue (design A1). @return array{verifications: int, signals: int, reports: int, reviews: int} */
    public static function queue(): array
    {
        return [
            'verifications' => LotVerification::withoutGlobalScopes()->where('status', VerificationStatus::Submitted)->count(),
            'signals' => FraudSignal::where('status', SignalStatus::Open)->distinct()->count('vehicle_id'),
            'reports' => Report::where('status', ReportStatus::Open)->where('reportable_type', '!=', Review::class)->count(),
            'reviews' => Report::where('status', ReportStatus::Open)->where('reportable_type', Review::class)->distinct()->count('reportable_id'),
        ];
    }
}
