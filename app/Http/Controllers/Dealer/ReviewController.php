<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Appointments\Support\AppointmentText;
use App\Domain\Lots\Models\Lot;
use App\Domain\Trust\Actions\ReplyToReview;
use App\Domain\Trust\Enums\ReviewStatus;
use App\Domain\Trust\Models\Review;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Reviews of the seller and the one reply each (TDD M14). Owners and managers reply. */
class ReviewController extends Controller
{
    public function index(Lot $lot): Response
    {
        Gate::authorize('view', $lot);

        $reviews = Review::query()->with(['author', 'appointment'])->latest()->paginate(20);
        $counts = Review::query()->visible()->toBase()->selectRaw('rating, count(*) as total')->groupBy('rating')->pluck('total', 'rating');

        return Inertia::render('Dealer/Reviews', [
            'summary' => [
                'rating' => $lot->rating !== null ? round($lot->rating, 1) : null,
                'count' => $lot->reviews_count,
                'shown' => $lot->publicRating() !== null,
                'min' => Review::MIN_FOR_RATING,
                'bars' => collect([5, 4, 3, 2, 1])->map(fn (int $r) => ['rating' => $r, 'count' => (int) ($counts[$r] ?? 0)]),
            ],
            'reviews' => $reviews->through(fn (Review $r) => [
                'ulid' => $r->ulid,
                'author' => $r->authorName(),
                'rating' => $r->rating,
                'tags' => $r->tagLabels(),
                'body' => $r->body,
                'visit' => $r->appointment ? AppointmentText::what($r->appointment) : null,
                'date' => $r->created_at->copy()->setTimezone($lot->timezone)->format('j M Y'),
                'edited' => $r->edited_at !== null,
                'hidden' => $r->status === ReviewStatus::Hidden,
                'reply' => $r->reply,
                'reply_date' => $r->reply_at?->copy()->setTimezone($lot->timezone)->format('j M Y'),
            ]),
            'canReply' => Gate::allows('update', $lot),
        ]);
    }

    public function reply(Request $request, Lot $lot, Review $review, ReplyToReview $reply): RedirectResponse
    {
        Gate::authorize('update', $lot);
        $data = $request->validate(['reply' => ['required', 'string', 'max:1000']]);

        $reply->run($review, $request->user(), $data['reply']);

        return back()->with('success', 'Reply posted. Buyers see it under the review.');
    }
}
