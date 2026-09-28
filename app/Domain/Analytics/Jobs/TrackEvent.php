<?php

namespace App\Domain\Analytics\Jobs;

use App\Domain\Analytics\Models\AnalyticsEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/** Writes one analytics event off the request (TDD M15, queue: default). */
class TrackEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $lotId,
        public readonly ?int $vehicleId,
        public readonly string $type,
        public readonly ?string $channel,
        public readonly Carbon $occurredAt,
    ) {}

    public function handle(): void
    {
        AnalyticsEvent::create([
            'lot_id' => $this->lotId,
            'vehicle_id' => $this->vehicleId,
            'type' => $this->type,
            'channel' => $this->channel !== null ? mb_substr($this->channel, 0, 24) : null,
            'occurred_at' => $this->occurredAt,
        ]);
    }
}
