<?php

namespace App\Domain\Trust\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Trust\Models\Review;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The seller answers a review, once (TDD M14). */
class ReplyToReview
{
    public function run(Review $review, User $staff, string $reply): Review
    {
        return DB::transaction(function () use ($review, $staff, $reply): Review {
            $locked = Review::withoutGlobalScopes()->whereKey($review->id)->lockForUpdate()->firstOrFail();

            if ($locked->reply !== null) {
                throw ValidationException::withMessages(['reply' => 'You have already replied to this review.']);
            }

            $locked->forceFill(['reply' => trim($reply), 'replied_by' => $staff->id, 'reply_at' => now()])->save();
            AuditLog::record('review.replied', $locked, [], $staff, $locked->lot_id);

            return $locked;
        });
    }
}
