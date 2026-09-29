<?php

namespace App\Domain\Engagement\Jobs;

use App\Domain\Engagement\Models\Broadcast;
use App\Domain\Engagement\Models\EngagementMessage;
use App\Domain\Engagement\Notifications\EngagementNotice;
use App\Domain\Engagement\Support\BroadcastAudience;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers a broadcast: one tracked message per recipient, then the notification. Safe to retry:
 * people who already have this broadcast's message are skipped.
 */
class DeliverBroadcast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public function __construct(public readonly int $broadcastId)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $broadcast = Broadcast::find($this->broadcastId);
        if ($broadcast === null || $broadcast->status !== 'sending') {
            return;
        }

        $already = EngagementMessage::query()->where('broadcast_id', $broadcast->id)->pluck('user_id')->flip();
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/', $broadcast->body) ?: [])));
        $url = $broadcast->cta_url ?: route('dealer.home');

        foreach (BroadcastAudience::recipients($broadcast->audience) as $userId => ['user' => $user, 'lot' => $lot]) {
            if ($already->has($userId)) {
                continue;
            }

            $message = EngagementMessage::create([
                'broadcast_id' => $broadcast->id,
                'user_id' => $user->id,
                'lot_id' => $lot->id,
                'url' => $url,
                'sent_at' => now(),
            ]);
            $user->notify(new EngagementNotice($message, 'news', $broadcast->title, $lines, $broadcast->cta_label ?: 'Open LotLink', $broadcast->channels));
        }

        $broadcast->update([
            'status' => 'sent',
            'sent_at' => now(),
            'recipients_count' => EngagementMessage::query()->where('broadcast_id', $broadcast->id)->count(),
        ]);
    }
}
