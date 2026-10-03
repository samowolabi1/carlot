<?php

namespace App\Domain\Finance\Notifications;

use App\Domain\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To a lender's team: a new application, a buyer's message or document, a withdrawal, or news about the lender account. */
class LenderAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $text, public readonly string $url, public readonly bool $email = true)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return NotificationPreferences::filter($notifiable, 'finance', $this->email && filled($notifiable->email ?? null) ? ['mail', 'database'] : ['database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('CarYard lender portal')->line($this->text)->action('Open the lender portal', $this->url);
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'finance', 'text' => $this->text, 'url' => $this->url];
    }
}
