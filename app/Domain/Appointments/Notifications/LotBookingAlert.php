<?php

namespace App\Domain\Appointments\Notifications;

use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Support\AppointmentText;
use App\Domain\Messaging\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * To the lot's WhatsApp: a new booking, a buyer's change, or a request left unconfirmed
 * for 4 hours (escalated to the owner).
 */
class LotBookingAlert extends Notification implements ShouldQueue
{
    use Queueable;

    private const EVENTS = [
        'new' => 'New booking',
        'needs_confirmation' => 'New booking request (please confirm)',
        'rescheduled' => 'Booking moved by the buyer',
        'cancelled' => 'Booking cancelled by the buyer',
        'escalated' => 'Still waiting for you to confirm',
    ];

    public function __construct(public readonly Appointment $appointment, public readonly string $event)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['phone'];
    }

    public function toPhone(object $notifiable): Message
    {
        $a = $this->appointment;
        $lot = AppointmentText::lot($a);
        $event = self::EVENTS[$this->event] ?? $this->event;
        $customer = $a->customer()->value('name') ?? 'A buyer';
        $what = AppointmentText::what($a);
        $when = AppointmentText::when($a, $lot);
        $url = route('dealer.calendar', ['lot' => $lot->slug, 'week' => $a->starts_at->copy()->setTimezone($lot->timezone)->startOfWeek()->toDateString()]);

        return new Message(
            'dealer_booking_alert',
            [$event, $customer, $what, $when],
            "{$event}: {$customer}, {$what}, {$when}. Open calendar: {$url}",
            Message::suffix($url),
        );
    }
}
