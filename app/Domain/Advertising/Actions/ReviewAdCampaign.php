<?php

namespace App\Domain\Advertising\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Advertising\Enums\AdStatus;
use App\Domain\Advertising\Models\AdCampaign;
use App\Domain\Advertising\Notifications\AdReviewed;
use App\Domain\Advertising\Support\AdSchedule;
use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Actions\RefundPayment;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * An admin checks a paid advert (images and words are the seller's own). Approving schedules it from
 * the date asked for (or from now, so no days are lost, or from the next free slot); rejecting
 * refunds it; removing takes a running advert down.
 */
class ReviewAdCampaign
{
    public function __construct(private readonly RefundPayment $refund) {}

    public function approve(AdCampaign $campaign, User $admin): AdCampaign
    {
        $campaign = DB::transaction(function () use ($campaign, $admin) {
            $campaign = AdCampaign::withoutGlobalScopes()->lockForUpdate()->findOrFail($campaign->id);
            $this->mustBe($campaign, AdStatus::InReview);

            $asked = AdSchedule::dayStart($campaign->requested_start);
            $start = AdSchedule::nextStart($campaign->placement, $asked->greaterThan(now()) ? $asked : now()->startOfMinute(), $campaign->days, $campaign->id);

            $campaign->forceFill([
                'status' => AdStatus::Approved,
                'starts_at' => $start,
                'ends_at' => $start->copy()->addDays($campaign->days),
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => null,
            ])->save();
            AuditLog::record('advert.approved', $campaign, ['starts_at' => $start->toIso8601String()], $admin, $campaign->lot_id);

            return $campaign;
        });

        $this->tell($campaign, 'approved');

        return $campaign;
    }

    public function reject(AdCampaign $campaign, User $admin, string $note): AdCampaign
    {
        $this->mustBe($campaign, AdStatus::InReview);

        $payment = $campaign->payment_id ? Payment::find($campaign->payment_id) : null;
        if ($payment?->status === PaymentStatus::Success) {
            $this->refund->run($payment, $admin); // marks the campaign rejected
        }

        $campaign->refresh()->forceFill(['status' => AdStatus::Rejected, 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
        AuditLog::record('advert.rejected', $campaign, ['note' => $note, 'refunded' => $payment?->status === PaymentStatus::Refunded], $admin, $campaign->lot_id);
        $this->tell($campaign, 'rejected');

        return $campaign;
    }

    /** Takes down an approved advert (e.g. after complaints). Refund separately from Payments if it's owed. */
    public function remove(AdCampaign $campaign, User $admin, string $note): AdCampaign
    {
        $this->mustBe($campaign, AdStatus::Approved);

        $campaign->forceFill(['status' => AdStatus::Removed, 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_note' => $note])->save();
        AuditLog::record('advert.removed', $campaign, ['note' => $note], $admin, $campaign->lot_id);
        $this->tell($campaign, 'removed');

        return $campaign;
    }

    private function mustBe(AdCampaign $campaign, AdStatus $status): void
    {
        if ($campaign->status !== $status) {
            throw ValidationException::withMessages(['advert' => 'This advert has already been dealt with.']);
        }
    }

    private function tell(AdCampaign $campaign, string $event): void
    {
        $lot = Lot::withoutGlobalScopes()->find($campaign->lot_id);
        if ($lot === null) {
            return;
        }

        $people = $lot->members()->wherePivotIn('role', [LotRole::Owner->value, LotRole::Manager->value])->get();
        Notification::send($people, new AdReviewed($campaign, $lot, $event));
    }
}
