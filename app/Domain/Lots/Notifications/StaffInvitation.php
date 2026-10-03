<?php

namespace App\Domain\Lots\Notifications;

use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Lot $lot,
        public readonly LotInvitation $invitation,
        public readonly string $url,
    ) {
        $this->onQueue('notifications');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Join {$this->lot->name} on CarYard")
            ->line("{$this->lot->name} has invited you to join their team as {$this->invitation->role->label()}.")
            ->action('Accept invitation', $this->url)
            ->line('This invitation expires in '.config('lotlink.invitation_ttl_days').' days.');
    }
}
