<?php

namespace App\Domain\Leads\Events;

use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;
use App\Domain\Lots\Models\Lot;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A chat message, pushed to the open conversation and to both sides' badge channels
 * (TDD: Realtime channels). Without Reverb the screens poll instead.
 */
class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly Message $message, public readonly Conversation $conversation) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $lead = Lead::withoutGlobalScopes()->find($this->conversation->lead_id);

        return array_values(array_filter([
            new PrivateChannel("conversation.{$this->conversation->ulid}"),
            $lead ? new PrivateChannel("lot.{$lead->lot_id}") : null,
            $lead?->customer_id ? new PrivateChannel("user.{$lead->customer_id}") : null,
        ]));
    }

    public function broadcastAs(): string
    {
        return 'MessageSent';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $lead = Lead::withoutGlobalScopes()->find($this->conversation->lead_id);
        $tz = $lead ? (Lot::whereKey($lead->lot_id)->value('timezone') ?? config('lotlink.timezone')) : config('lotlink.timezone');

        return ['conversation' => $this->conversation->ulid, 'message' => $this->message->present((string) $tz)];
    }
}
