<?php

namespace App\Domain\Trust\Notifications;

use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Support\AppointmentText;
use App\Domain\Messaging\Message;
use App\Domain\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/** "How was your visit?" 2 hours after a completed appointment (TDD M14, review_invite template). */
class ReviewInvite extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Appointment $appointment)
    {
        $this->onQueue('notifications');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return NotificationPreferences::filter($notifiable, 'reviews', ['phone', 'database']);
    }

    /** Signed, so it opens the review page without signing in; valid while the review can be written. */
    public function url(): string
    {
        return URL::signedRoute('reviews.edit', ['appointment' => $this->appointment->ulid], now()->addDays(30));
    }

    public function toPhone(object $notifiable): Message
    {
        $lot = AppointmentText::lot($this->appointment);
        $url = $this->url();

        return new Message('review_invite', [$lot->name, AppointmentText::what($this->appointment)],
            "How was your visit to {$lot->name}? Leave a quick review to help other buyers: {$url}", Message::suffix($url));
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        $lot = AppointmentText::lot($this->appointment);

        return [
            'kind' => 'review',
            'text' => "How was your visit to {$lot->name}? Tap to leave a review.",
            'url' => route('reviews.edit', $this->appointment),
        ];
    }
}
