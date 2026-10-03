<?php

namespace App\Domain\Advertising\Notifications;

use App\Domain\Advertising\Models\AdCampaign;
use App\Domain\Lots\Models\Lot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the seller's owner and managers: their advert was approved, rejected (and refunded) or taken down. */
class AdReviewed extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  'approved'|'rejected'|'removed'  $event */
    public function __construct(public readonly AdCampaign $campaign, public readonly Lot $lot, public readonly string $event)
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

        return (new MailMessage)->subject("Your {$this->campaign->placement->label()}: ".match ($this->event) {
            'approved' => 'approved',
            'rejected' => 'not approved',
            default => 'taken down',
        })->line($data['text'])->action('See your adverts', $data['url']);
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        $c = $this->campaign;
        $from = $c->starts_at?->copy()->setTimezone($this->lot->timezone)->format('D j M, g:ia');
        $note = $c->review_note ? " CarYard's note: {$c->review_note}" : '';

        return [
            'kind' => 'billing',
            'text' => match ($this->event) {
                'approved' => "Your {$c->placement->label()} \"{$c->headline}\" is approved and runs from {$from} for {$c->days} days.",
                'rejected' => "Your {$c->placement->label()} \"{$c->headline}\" wasn't approved and {$c->money()} is being refunded.{$note}",
                default => "CarYard took down your {$c->placement->label()} \"{$c->headline}\".{$note}",
            },
            'url' => route('dealer.ads.index', $this->lot),
        ];
    }
}
