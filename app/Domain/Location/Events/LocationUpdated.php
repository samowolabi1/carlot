<?php

namespace App\Domain\Location\Events;

use App\Domain\Location\Models\LocationSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** A new point, or the end of sharing, on private channel location-session.{ulid} (TDD M8). */
class LocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly LocationSession $session) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("location-session.{$this->session->ulid}")];
    }

    public function broadcastAs(): string
    {
        return 'LocationUpdated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['point' => $this->session->point(), 'live' => $this->session->isLive()];
    }
}
