<?php

namespace App\Domain\Trust\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use App\Domain\Trust\Enums\ReviewStatus;
use App\Domain\Trust\Models\Review;
use App\Domain\Trust\Notifications\ReviewReceived;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * One review per completed visit (TDD M14); the buyer can change it for 14 days. The lot's
 * owner and managers hear about new reviews.
 */
class SubmitReview
{
    /** @param list<string> $tags */
    public function run(Appointment $appointment, User $buyer, int $rating, array $tags = [], ?string $body = null): Review
    {
        if ($appointment->customer_id !== $buyer->id) {
            throw ValidationException::withMessages(['rating' => 'Only the buyer who booked this visit can review it.']);
        }

        if (! $appointment->canBeReviewed()) {
            throw ValidationException::withMessages(['rating' => 'You can review a visit once it has happened.']);
        }

        $tags = array_values(array_intersect(array_keys(Review::TAGS), $tags));
        $body = filled($body) ? trim((string) $body) : null;

        $review = DB::transaction(function () use ($appointment, $buyer, $rating, $tags, $body): Review {
            $review = Review::withoutGlobalScopes()->where('appointment_id', $appointment->id)->lockForUpdate()->first();

            if ($review !== null) {
                if (! $review->canBeEdited()) {
                    throw ValidationException::withMessages(['rating' => 'Reviews can be changed for '.Review::EDIT_DAYS.' days after posting.']);
                }

                $review->update(['rating' => $rating, 'tags' => $tags ?: null, 'body' => $body, 'edited_at' => now()]);

                return $review;
            }

            return Review::create([
                'lot_id' => $appointment->lot_id,
                'appointment_id' => $appointment->id,
                'user_id' => $buyer->id,
                'rating' => $rating,
                'tags' => $tags ?: null,
                'body' => $body,
                'status' => ReviewStatus::Visible,
            ]);
        });

        RefreshLotRating::run($review->lot_id);

        if ($review->wasRecentlyCreated) {
            $lot = Lot::findOrFail($review->lot_id);
            $managers = $lot->members()->wherePivotIn('role', [LotRole::Owner->value, LotRole::Manager->value])->get();
            Notification::send($managers, new ReviewReceived($review));
        }

        return $review;
    }
}
