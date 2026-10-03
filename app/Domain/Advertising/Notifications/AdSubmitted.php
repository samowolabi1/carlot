<?php

namespace App\Domain\Advertising\Notifications;

use App\Domain\Advertising\Models\AdCampaign;
use App\Domain\Lots\Models\Lot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To CarYard admins: a paid advert is waiting to be checked. */
class AdSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly AdCampaign $campaign)
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

        return (new MailMessage)->subject('Advert to check: '.$this->campaign->headline)->line($data['text'])->action('Check it', $data['url']);
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        $lot = Lot::withoutGlobalScopes()->withTrashed()->find($this->campaign->lot_id);

        return [
            'kind' => 'moderation',
            'text' => ($lot->name ?? 'A seller')." paid for a {$this->campaign->placement->label()} ({$this->campaign->days} days): \"{$this->campaign->headline}\". Check it before it runs.",
            'url' => route('filament.admin.resources.ad-campaigns.index'),
        ];
    }
}
