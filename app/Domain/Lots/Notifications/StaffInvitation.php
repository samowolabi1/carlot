<?php

namespace App\Domain\Lots\Notifications;

use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The invitation by id, not the model: re-inviting replaces it and owners can revoke it, and a queued
     * notification holding a deleted model fails instead of quietly not sending.
     */
    public readonly int $invitationId;

    public readonly LotRole $role;

    public function __construct(
        public readonly Lot $lot,
        LotInvitation $invitation,
        public readonly string $url,
    ) {
        $this->invitationId = (int) $invitation->getKey();
        $this->role = $invitation->role;
        $this->onQueue('notifications');
    }

    /** Only while the invitation is still open (not replaced, revoked or accepted). */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return LotInvitation::withoutGlobalScopes()->whereKey($this->invitationId)->whereNull('accepted_at')->exists();
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
            ->line("{$this->lot->name} has invited you to join their team as {$this->role->label()}.")
            ->action('Accept invitation', $this->url)
            ->line('This invitation expires in '.config('lotlink.invitation_ttl_days').' days.');
    }
}
