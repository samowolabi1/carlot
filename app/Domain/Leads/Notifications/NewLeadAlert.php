<?php

namespace App\Domain\Leads\Notifications;

use App\Domain\Leads\Models\Lead;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** To the seller's staff, in the notification centre: a new lead arrived (TDD matrix). */
class NewLeadAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Lead $lead)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return NotificationPreferences::filter($notifiable, 'leads', ['database']);
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        $lead = $this->lead->loadMissing(['customer', 'vehicle.make', 'vehicle.model']);
        $lot = Lot::findOrFail($lead->lot_id);
        $who = $lead->customer->name ?? 'A buyer';
        $about = $lead->vehicle?->title();

        return [
            'kind' => 'lead',
            'text' => "New lead at {$lot->name}: {$who}".($about ? " about the {$about}" : '')." ({$lead->source->label()}).",
            'url' => route('dealer.leads.show', [$lot->slug, $lead->ulid]),
        ];
    }
}
