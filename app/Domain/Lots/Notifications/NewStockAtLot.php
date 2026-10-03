<?php

namespace App\Domain\Lots\Notifications;

use App\Domain\Lots\Models\Lot;
use App\Domain\Messaging\Message;
use App\Domain\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * To buyers following a seller: it listed new cars (TDD M5). The in-app bell arrives with
 * the notification centre (S8); WhatsApp goes now, as following is the buyer's opt-in.
 */
class NewStockAtLot extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Lot $lot, public readonly int $count, public readonly string $example)
    {
        $this->onQueue('notifications');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return NotificationPreferences::filter($notifiable, 'new_stock', ['phone', 'database']);
    }

    public function summary(): string
    {
        return $this->count === 1
            ? "{$this->lot->name} just listed a {$this->example}."
            : "{$this->lot->name} just listed {$this->count} cars, including a {$this->example}.";
    }

    public function toPhone(object $notifiable): Message
    {
        $url = route('lots.show', $this->lot->slug);

        return new Message('lot_new_stock', [$this->lot->name, (string) $this->count, $this->example], $this->summary()." See them: {$url} (Unfollow the seller to stop these.)", Message::suffix($url));
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'new_stock', 'lot' => $this->lot->slug, 'text' => $this->summary(), 'url' => route('lots.show', $this->lot->slug)];
    }
}
