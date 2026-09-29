<?php

namespace App\Domain\Finance\Notifications;

use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The partner's answer on a pre-qualification (in-app and email; no WhatsApp template needed). */
class FinanceUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly FinanceApplication $application)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return NotificationPreferences::filter($notifiable, 'finance', filled($notifiable->email ?? null) ? ['mail', 'database'] : ['database']);
    }

    private function line(): string
    {
        $car = $this->application->vehicle?->title() ?? 'your car';
        $partner = config('lotlink.finance_partner.name');

        return $this->application->status === 'pre_approved'
            ? "{$partner} pre-approved a loan of ".$this->application->money($this->application->approved_amount)." for {$car}."
            : "{$partner} couldn't pre-approve the loan for {$car}.";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Your finance pre-qualification')->line($this->line())
            ->line((string) $this->application->partner_message)->action('See details', route('finance.index'));
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'finance', 'text' => $this->line(), 'url' => route('finance.index')];
    }
}
