<?php

namespace App\Domain\Trust\Notifications;

use App\Domain\Lots\Models\Lot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A note from LotLink's team to a lot owner about a listing or report (in-app and email). */
class ModerationNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Lot $lot, public readonly string $text)
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
        return (new MailMessage)->subject('A message from LotLink about '.$this->lot->name)->line($this->text)->action('Open your stock', route('dealer.vehicles.index', $this->lot));
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'moderation', 'text' => $this->text, 'url' => route('dealer.vehicles.index', $this->lot)];
    }
}
