<?php

namespace App\Domain\Accounts\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A security notice: the way someone signs in changed (password added, changed or removed, Google
 * connected or disconnected). Always sent, whatever the notification settings, so a stranger's
 * change doesn't go unnoticed.
 */
class SignInChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $what)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your CarYard sign-in changed')
            ->line($this->toArray($notifiable)['text'])
            ->line('If this wasn\'t you, sign in with a one-time code, remove the password or Google account, and contact CarYard support.')
            ->action('Check sign-in and security', route('account.security'));
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'info', 'text' => "Sign-in and security: {$this->what}.", 'url' => route('account.security')];
    }
}
