<?php

namespace App\Domain\Trust\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Trust\Enums\ReportStatus;
use App\Domain\Trust\Enums\ReviewStatus;
use App\Domain\Trust\Models\Report;
use App\Domain\Trust\Models\Review;
use Illuminate\Support\Facades\DB;

/** An admin keeps a reported review hidden, or puts it back (TDD M14). */
class ModerateReview
{
    public function restore(Review $review, User $admin): void
    {
        $this->decide($review, $admin, ReviewStatus::Visible, ReportStatus::Dismissed);
    }

    public function remove(Review $review, User $admin): void
    {
        $this->decide($review, $admin, ReviewStatus::Hidden, ReportStatus::Actioned);
    }

    private function decide(Review $review, User $admin, ReviewStatus $status, ReportStatus $reports): void
    {
        DB::transaction(function () use ($review, $admin, $status, $reports): void {
            $review->refresh()->forceFill(['status' => $status])->save();
            Report::where('reportable_type', Review::class)->where('reportable_id', $review->id)->where('status', ReportStatus::Open)
                ->update(['status' => $reports, 'handled_by' => $admin->id, 'handled_at' => now()]);
            AuditLog::record('admin.review_'.($status === ReviewStatus::Visible ? 'restored' : 'removed'), $review, [], $admin, $review->lot_id);
        });

        RefreshLotRating::run($review->lot_id);
    }
}
