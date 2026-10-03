<?php

namespace App\Domain\Appointments\Notifications;

use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Support\AppointmentText;
use App\Domain\Appointments\Support\IcsCalendar;
use App\Domain\Messaging\Message;
use App\Domain\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the buyer: booking received (waiting for the seller), confirmed, rescheduled or
 * cancelled. Phone (WhatsApp, else SMS) always; email with a calendar file when we
 * have their address.
 */
class BookingNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const RECEIVED = 'received';

    public const CONFIRMED = 'confirmed';

    public const RESCHEDULED = 'rescheduled';

    public const CANCELLED = 'cancelled';

    public function __construct(public readonly Appointment $appointment, public readonly string $event)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return NotificationPreferences::filter($notifiable, 'bookings', $notifiable->email ? ['phone', 'mail', 'database'] : ['phone', 'database']);
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
        $when = AppointmentText::when($a, $lot);
        $url = AppointmentText::manageUrl($a);

        return match ($this->event) {
            self::CONFIRMED, self::RECEIVED => $a->status === AppointmentStatus::Confirmed
                ? new Message('booking_confirmed', [$notifiable->name ?? 'there', $what, $lot->name, $when],
                    "You're booked: {$what} at {$lot->name}, {$when}. We'll remind you before. Manage: {$url}", Message::suffix($url))
                : new Message('booking_pending', [$notifiable->name ?? 'there', $what, $lot->name, $when],
                    "{$lot->name} has your request for {$what} on {$when}. We'll message you when they confirm. Manage: {$url}", Message::suffix($url)),
            self::RESCHEDULED => new Message('appointment_update', [$what, $lot->name, "moved to {$when}"],
                "{$lot->name} moved your {$what} to {$when}. Manage: {$url}", Message::suffix($url)),
            default => new Message('appointment_update', [$what, $lot->name, 'cancelled'.($a->cancel_reason ? ": {$a->cancel_reason}" : '')],
                "{$lot->name} cancelled your {$what} on {$when}".($a->cancel_reason ? " ({$a->cancel_reason})" : '').". Book another time: {$url}", Message::suffix($url)),
        };
    }

    /** @return array<string, string> the notification centre entry */
    public function toArray(object $notifiable): array
    {
        $a = $this->appointment;
        $lot = AppointmentText::lot($a);
        $what = AppointmentText::what($a);
        $when = AppointmentText::when($a, $lot);

        return [
            'kind' => 'booking',
            'text' => match (true) {
                $this->event === self::CANCELLED => "{$lot->name} cancelled your {$what} on {$when}.",
                $this->event === self::RESCHEDULED => "Your {$what} at {$lot->name} moved to {$when}.",
                $a->status === AppointmentStatus::Confirmed => "{$what} confirmed for {$when} at {$lot->name}.",
                default => "{$lot->name} has your request for {$what} on {$when}.",
            },
            'url' => AppointmentText::manageUrl($a),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $a = $this->appointment;
        $lot = AppointmentText::lot($a);
        $what = AppointmentText::what($a);
        $when = AppointmentText::when($a, $lot);

        $mail = (new MailMessage)->greeting('Hi '.($notifiable->name ?? 'there').',');

        $mail = match (true) {
            $this->event === self::CANCELLED => $mail->subject("Cancelled: {$what} at {$lot->name}")
                ->line("{$lot->name} cancelled your {$what} on {$when}.".($a->cancel_reason ? " Reason: {$a->cancel_reason}." : '')),
            $this->event === self::RESCHEDULED => $mail->subject("New time: {$what} at {$lot->name}")
                ->line("Your {$what} at {$lot->name} is now on {$when}."),
            $a->status === AppointmentStatus::Confirmed => $mail->subject("You're booked: {$what} at {$lot->name}")
                ->line("Your {$what} at {$lot->name} is confirmed for {$when}."),
            default => $mail->subject("Request sent: {$what} at {$lot->name}")
                ->line("{$lot->name} has your request for {$what} on {$when}. We'll let you know when they confirm."),
        };

        if ($lot->directionsUrl() && $this->event !== self::CANCELLED) {
            $mail->line('Directions: '.$lot->directionsUrl());
        }

        $mail->action('Manage your booking', AppointmentText::manageUrl($a));

        if ($this->event !== self::CANCELLED) {
            $mail->attachData(IcsCalendar::for($a, $lot, "{$what} at {$lot->name}"), 'caryard-visit.ics', ['mime' => 'text/calendar']);
        }

        return $mail;
    }
}
