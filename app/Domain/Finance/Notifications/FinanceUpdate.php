<?php

namespace App\Domain\Finance\Notifications;

use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the buyer: the lender moved their car loan application or wrote to them (in-app, push and email). */
class FinanceUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly FinanceApplication $application, public readonly string $text)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return NotificationPreferences::filter($notifiable, 'finance', filled($notifiable->email ?? null) ? ['mail', 'database'] : ['database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your car loan application')->line($this->text)
            ->action('See your application', route('finance.show', $this->application))
            ->line('LotLink doesn\'t lend money: '.$this->application->lenderName().' makes the decisions.');
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'finance', 'text' => $this->text, 'url' => route('finance.show', $this->application)];
    }
}
