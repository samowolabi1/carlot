<?php

namespace App\Domain\Leads\Notifications;

use App\Domain\Leads\Models\Lead;
use App\Domain\Lots\Models\Lot;
use App\Domain\Messaging\Message;
use App\Domain\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** To the assigned rep: the follow-up date on a lead has come (TDD M11). */
class LeadFollowUpDue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Lead $lead)
    {
        $this->onQueue('notifications');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return NotificationPreferences::filter($notifiable, 'leads', ['phone', 'database']);
    }

    public function toPhone(object $notifiable): Message
    {
        [$who, $lot, $url] = $this->context();

        return new Message('follow_up_due', [$notifiable->name ?? 'there', 'Follow up with', $who, $lot->name], "Follow-up due at {$lot->name}: {$who}. Open: {$url}", Message::suffix($url));
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        [$who, $lot, $url] = $this->context();

        return ['kind' => 'follow_up', 'text' => "Follow up with {$who} today.", 'url' => $url];
    }

    /** @return array{string, Lot, string} */
    private function context(): array
    {
        $lead = $this->lead->loadMissing(['customer', 'vehicle.make', 'vehicle.model']);
        $lot = Lot::findOrFail($lead->lot_id);
        $who = ($lead->customer->name ?? 'a buyer').($lead->vehicle ? " ({$lead->vehicle->title()})" : '');

        return [$who, $lot, route('dealer.leads.show', [$lot->slug, $lead->ulid])];
    }
}
