<?php

namespace App\Domain\Leads\Events;

use App\Domain\Leads\Models\Lead;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class LeadCreated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public readonly Lead $lead) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("lot.{$this->lead->lot_id}");
    }

    public function broadcastAs(): string
    {
        return 'LeadCreated';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['lead' => $this->lead->ulid, 'source' => $this->lead->source->value];
    }
}
