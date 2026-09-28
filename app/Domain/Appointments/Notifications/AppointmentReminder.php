<?php

namespace App\Domain\Appointments\Notifications;

use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Support\AppointmentText;
use App\Domain\Messaging\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** 24 hours and 2 hours before a confirmed visit, with directions (TDD M7). */
class AppointmentReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Appointment $appointment, public readonly string $window)
    {
        $this->onQueue('notifications');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['phone'];
    }

    public function prefersWhatsApp(object $notifiable): bool
    {
        return $this->appointment->whatsapp_reminders;
    }

    public function toPhone(object $notifiable): Message
    {
        $a = $this->appointment;
        $lot = AppointmentText::lot($a);
        $what = AppointmentText::what($a);
        $local = $a->starts_at->copy()->setTimezone($lot->timezone);
        $when = ($local->isToday() ? 'today' : ($local->isTomorrow() ? 'tomorrow' : $local->format('D j M'))).' at '.$local->format('H:i');
        $directions = $lot->directionsUrl() ?? $lot->address ?? $lot->name;
        $url = AppointmentText::manageUrl($a);

        return new Message(
            'appointment_reminder',
            [$what, $lot->name, $when, $directions],
            "Reminder: {$what} at {$lot->name} {$when}. Directions: {$directions}. Reschedule or cancel: {$url}",
            Message::suffix($url),
        );
    }
}
