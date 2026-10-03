<?php

namespace App\Domain\Location\Notifications;

use App\Domain\Appointments\Support\AppointmentText;
use App\Domain\Location\Models\LocationSession;
use App\Domain\Messaging\Message;
use App\Domain\Support\Name;
use App\Domain\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * To the other side of a booking: live location is being shared. The seller's team sees it in
 * the notification centre; a buyer also gets it on WhatsApp (appointment_update template).
 */
class LocationShared extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly LocationSession $session)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->session->sharer_side === LocationSession::LOT
            ? NotificationPreferences::filter($notifiable, 'bookings', ['phone', 'database'])
            : ['database'];
    }

    public function toPhone(object $notifiable): Message
    {
        $a = $this->session->appointment;
        $lot = AppointmentText::lot($a);
        $what = AppointmentText::what($a);
        $url = route('location.show', $this->session);

        return new Message('appointment_update', [$what, $lot->name, "{$lot->name} is sharing their live location"],
            "{$lot->name} is sharing their live location for your {$what}: {$url}", Message::suffix($url));
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        $a = $this->session->appointment;
        $lot = AppointmentText::lot($a);
        $what = AppointmentText::what($a);
        $who = $this->session->sharer_side === LocationSession::CUSTOMER ? Name::short($this->session->sharer->name) : $lot->name;

        return [
            'kind' => 'location',
            'text' => "{$who} is sharing live location for the {$what} at ".AppointmentText::when($a, $lot).'.',
            'url' => route('location.show', $this->session),
        ];
    }
}
