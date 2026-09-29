<?php

namespace App\Domain\Accounts\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Forgot your password?": a one-hour link to set a new one. Sent straight away (the person is waiting). */
class ResetPasswordLink extends Notification
{
    public function __construct(public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);

        return (new MailMessage)->subject('Reset your LotLink password')
            ->line('Someone (hopefully you) asked to reset the password on your LotLink account.')
            ->action('Choose a new password', $url)
            ->line("The link works once and expires in {$minutes} minutes.")
            ->line('Didn\'t ask for this? Ignore this email: your password stays the same. You can always sign in with a one-time code instead.');
    }
}
