<?php

namespace App\Domain\Appointments\Notifications;

use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Support\AppointmentText;
use App\Domain\Billing\Models\Payment;
use App\Domain\Messaging\Message;
use App\Domain\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** The buyer's test-drive deposit is on its way back (appointment_update template). */
class DepositRefunded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Appointment $appointment, public readonly Payment $payment)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return NotificationPreferences::filter($notifiable, 'bookings', ['phone', 'database']);
    }

    public function toPhone(object $notifiable): Message
    {
        $lot = AppointmentText::lot($this->appointment);
        $what = AppointmentText::what($this->appointment);
        $url = AppointmentText::manageUrl($this->appointment);

        return new Message('appointment_update', [$what, $lot->name, "your {$this->payment->money()} deposit has been refunded"],
            "Your {$this->payment->money()} deposit for the {$what} at {$lot->name} has been refunded. It can take a few days to show.", Message::suffix($url));
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        $lot = AppointmentText::lot($this->appointment);

        return [
            'kind' => 'booking',
            'text' => "Your {$this->payment->money()} test-drive deposit at {$lot->name} has been refunded.",
            'url' => AppointmentText::manageUrl($this->appointment),
        ];
    }
}
