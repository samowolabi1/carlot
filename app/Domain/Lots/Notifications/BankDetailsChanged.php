<?php

namespace App\Domain\Lots\Notifications;

use App\Domain\Lots\Models\Lot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the owner and managers: the bank details shown to customers changed (a guard against fraud). */
class BankDetailsChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Lot $lot, public readonly string $what, public readonly string $by)
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
        $data = $this->toArray($notifiable);

        return (new MailMessage)->subject("Bank details changed at {$this->lot->name}")
            ->line($data['text'])
            ->line('If you did not expect this, check your team and contact LotLink support.')
            ->action('See bank details', $data['url']);
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'billing',
            'text' => "Bank details for customers at {$this->lot->name}: {$this->what} by {$this->by}.",
            'url' => route('dealer.settings', $this->lot).'#bank',
        ];
    }
}
