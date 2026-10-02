<?php

namespace App\Domain\Admin\Notifications;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminRole;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/** An owner invited someone to the LotLink admin team: a 7-day link to set their password, then 2FA in /admin. */
class AdminInvitation extends Notification
{
    use Queueable;

    public const DAYS = 7;

    public function __construct(public readonly User $invitedBy, public readonly AdminRole $role) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        $url = URL::temporarySignedRoute('admin.invitation', now()->addDays(self::DAYS), ['user' => $notifiable->ulid]);

        return (new MailMessage)
            ->subject('You\'re invited to the LotLink admin team')
            ->greeting('Hello'.($notifiable->name ? ' '.strtok((string) $notifiable->name, ' ') : '').',')
            ->line("{$this->invitedBy->name} added you to the LotLink admin team as **{$this->role->label()}**: {$this->role->description()}")
            ->action('Set your password', $url)
            ->line('The link works for '.self::DAYS.' days. After setting a password you\'ll turn on two-step sign-in with an authenticator app.')
            ->line('If you weren\'t expecting this, ignore this email.');
    }
}
