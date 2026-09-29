<?php

namespace App\Domain\Engagement\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Engagement\Jobs\DeliverBroadcast;
use App\Domain\Engagement\Models\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sends a broadcast now (the delivery runs on the queue, so thousands of lots don't hold up the
 * admin page), schedules it for later, or cancels a schedule. Each broadcast is delivered once.
 */
class SendBroadcast
{
    public function run(Broadcast $broadcast, ?User $by = null): Broadcast
    {
        $broadcast = DB::transaction(function () use ($broadcast): Broadcast {
            /** @var Broadcast $locked */
            $locked = Broadcast::query()->whereKey($broadcast->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isEditable()) {
                throw ValidationException::withMessages(['broadcast' => 'This broadcast has already been sent.']);
            }
            $locked->update(['status' => 'sending']);

            return $locked;
        });

        AuditLog::record('admin.broadcast_sent', $broadcast, ['title' => $broadcast->title], $by);
        DeliverBroadcast::dispatch($broadcast->id);

        return $broadcast;
    }

    public function schedule(Broadcast $broadcast, \DateTimeInterface $at, ?User $by = null): void
    {
        if (! $broadcast->isEditable()) {
            throw ValidationException::withMessages(['scheduled_at' => 'This broadcast has already been sent.']);
        }
        $broadcast->update(['status' => 'scheduled', 'scheduled_at' => $at]);
        AuditLog::record('admin.broadcast_scheduled', $broadcast, ['at' => $broadcast->scheduled_at?->toIso8601String()], $by);
    }

    public function unschedule(Broadcast $broadcast, ?User $by = null): void
    {
        if ($broadcast->status === 'scheduled') {
            $broadcast->update(['status' => 'draft', 'scheduled_at' => null]);
            AuditLog::record('admin.broadcast_unscheduled', $broadcast, [], $by);
        }
    }

    /** Scheduled broadcasts that are due (every minute from the scheduler). */
    public function due(): int
    {
        $sent = 0;
        Broadcast::query()->where('status', 'scheduled')->where('scheduled_at', '<=', now())->get()
            ->each(function (Broadcast $b) use (&$sent): void {
                $this->run($b);
                $sent++;
            });

        return $sent;
    }
}
