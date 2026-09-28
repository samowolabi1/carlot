<?php

namespace App\Domain\LotManager\Notifications;

use App\Domain\Lots\Models\Lot;
use App\Domain\Messaging\Message;
use App\Domain\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * To the owner at 19:00 lot time (TDD M19: SendDailySummary). Template daily_summary:
 * [lot, date, walk-ins, new orders, money received, balances due, overdue instalments].
 *
 * @phpstan-type Summary array{date: string, walk_ins: int, orders: int, received: string, by_method: string, balances: string, overdue: int}
 */
class DailySummary extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param Summary $summary */
    public function __construct(public readonly Lot $lot, public readonly array $summary, public readonly string $url)
    {
        $this->onQueue('notifications');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return NotificationPreferences::filter($notifiable, 'summary', ['phone', 'database']);
    }

    public function toPhone(object $notifiable): Message
    {
        $s = $this->summary;

        return new Message(
            'daily_summary',
            [$this->lot->name, $s['date'], (string) $s['walk_ins'], (string) $s['orders'], $s['received'], $s['balances'], (string) $s['overdue']],
            "{$this->lot->name}, {$s['date']}: {$s['walk_ins']} walk-ins, {$s['orders']} new orders, {$s['received']} received"
                .($s['by_method'] ? " ({$s['by_method']})" : '').", {$s['balances']} still owed, {$s['overdue']} overdue instalments. Report: {$this->url}",
            Message::suffix($this->url),
        );
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        $s = $this->summary;

        return [
            'kind' => 'summary',
            'text' => "{$this->lot->name} today: {$s['walk_ins']} walk-ins, {$s['orders']} new orders, {$s['received']} received, {$s['overdue']} overdue instalments.",
            'url' => $this->url,
        ];
    }
}
