<?php

namespace App\Http\Controllers\Trust;

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Support\AppointmentText;
use App\Domain\Support\Name;
use App\Domain\Trust\Actions\SubmitReview;
use App\Domain\Trust\Models\Review;
use App\Http\Controllers\Controller;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "How was your visit?" (design 17). Opened by the buyer when signed in, or through the signed
 * link in the review invite, so it works straight from WhatsApp.
 */
class ReviewController extends Controller
{
    public function edit(Request $request, Appointment $appointment): Response
    {
        $buyer = $this->buyer($request, $appointment);
        $lot = AppointmentText::lot($appointment);
        $review = $appointment->review;

        return Inertia::render('Trust/Review', [
            'lot' => ['name' => $lot->name, 'url' => route('lots.show', $lot)],
            'visit' => AppointmentText::what($appointment).' · '.$appointment->starts_at->copy()->setTimezone($lot->timezone)->format('D j M'),
            'reviewable' => $appointment->canBeReviewed(),
            'review' => $review ? [
                'rating' => $review->rating,
                'tags' => $review->tags ?? [],
                'body' => $review->body,
                'editable' => $review->canBeEdited(),
                'editable_until' => $review->editableUntil()->setTimezone($lot->timezone)->format('j M'),
                'hidden' => $review->status->value === 'hidden',
                'reply' => $review->reply,
            ] : null,
            'tags' => collect(Review::TAGS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'author' => Name::short($buyer->name, 'A buyer'),
            // A signed visitor posts back to a signed URL; a signed-in buyer to the plain route.
            'action' => $request->user()?->is($buyer) ? route('reviews.store', $appointment) : URL::signedRoute('reviews.store', ['appointment' => $appointment->ulid], now()->addHours(2)),
            'back' => $request->user() ? route('bookings.index') : route('lots.show', $lot),
        ])->withViewData(['meta' => ['title' => 'Review your visit to '.$lot->name, 'robots' => 'noindex']]);
    }

    public function store(Request $request, Appointment $appointment, SubmitReview $submit): RedirectResponse
    {
        $buyer = $this->buyer($request, $appointment);

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'tags' => ['nullable', 'array', 'max:5'],
            'tags.*' => ['string', Rule::in(array_keys(Review::TAGS))],
            'body' => ['nullable', 'string', 'max:1000'],
        ], ['rating.required' => 'Tap the stars to give a rating.']);

        $review = $submit->run($appointment, $buyer, (int) $data['rating'], $data['tags'] ?? [], $data['body'] ?? null);
        $lot = AppointmentText::lot($appointment);

        return redirect($request->user() ? route('bookings.index') : route('lots.show', $lot))
            ->with('success', $review->wasRecentlyCreated ? "Thanks! Your review of {$lot->name} is live." : 'Review updated.');
    }

    private function buyer(Request $request, Appointment $appointment): User
    {
        $user = $request->user();

        if ($user !== null && $user->id === $appointment->customer_id) {
            return $user;
        }

        if (! $request->hasValidSignature()) {
            // Guests sign in with their phone and come back here.
            $user === null ? throw new AuthenticationException : abort(403);
        }

        return $appointment->customer()->firstOrFail();
    }
}
