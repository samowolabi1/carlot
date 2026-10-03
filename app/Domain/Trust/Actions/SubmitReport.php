<?php

namespace App\Domain\Trust\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;
use App\Domain\Lots\Models\Lot;
use App\Domain\Trust\Enums\ReportReason;
use App\Domain\Trust\Enums\ReportStatus;
use App\Domain\Trust\Enums\ReviewStatus;
use App\Domain\Trust\Models\Report;
use App\Domain\Trust\Models\Review;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anyone signed in can report a listing, lot, review or chat message (TDD M14). A review
 * reported by a buyer is hidden at once, and a listing with 3 open reports comes off the
 * marketplace, until an admin looks at them. One open report per person per item.
 */
class SubmitReport
{
    public function run(User $reporter, Model $subject, ReportReason $reason, ?string $details = null): Report
    {
        $lotId = match (true) {
            $subject instanceof Lot => $subject->id,
            $subject instanceof Message => Lead::withoutGlobalScopes()->whereKey($subject->conversation->lead_id)->value('lot_id'),
            default => $subject->getAttribute('lot_id'),
        };
        $member = $lotId !== null && $reporter->lots()->whereKey($lotId)->exists();

        if (($member && ($subject instanceof Vehicle || $subject instanceof Lot)) || ($subject instanceof Message && $subject->sender_id === $reporter->id)) {
            throw ValidationException::withMessages(['reason' => "You can't report your own content."]);
        }

        return DB::transaction(function () use ($reporter, $subject, $reason, $details, $lotId, $member): Report {
            $report = Report::firstOrNew(['user_id' => $reporter->id, 'reportable_type' => $subject::class, 'reportable_id' => $subject->getKey()]);

            if ($report->exists && $report->status === ReportStatus::Open) {
                throw ValidationException::withMessages(['reason' => "You've already reported this. Our team will look at it."]);
            }

            $report->fill(['lot_id' => $lotId, 'reason' => $reason, 'details' => filled($details) ? trim((string) $details) : null, 'status' => ReportStatus::Open, 'handled_by' => null, 'handled_at' => null])->save();

            // A seller reporting a review of itself doesn't hide it: that waits for an admin.
            if ($subject instanceof Review && $subject->status === ReviewStatus::Visible && ! $member) {
                $subject->forceFill(['status' => ReviewStatus::Hidden])->save();
                RefreshLotRating::run($subject->lot_id);
            }

            if ($subject instanceof Vehicle && ! $subject->isHeld()) {
                $open = Report::where('reportable_type', Vehicle::class)->where('reportable_id', $subject->id)->where('status', ReportStatus::Open)->count();

                if ($open >= Report::HOLD_AFTER) {
                    // save() so the car leaves the search index straight away.
                    $subject->held_at = now();
                    $subject->held_reason = 'Reported by buyers: CarYard is taking a look';
                    $subject->save();
                }
            }

            return $report;
        });
    }
}
