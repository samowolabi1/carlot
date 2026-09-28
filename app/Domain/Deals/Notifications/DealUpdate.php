<?php

namespace App\Domain\Deals\Notifications;

use App\Domain\Messaging\Message;
use App\Domain\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * To the buyer: something happened to their offer, trade-in or reservation (TDD notification
 * matrix: WhatsApp + in-app). Each kind has its own approved template:
 * offer_update, trade_in_update, reservation_update — [name, car, lot, update].
 */
class DealUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    public const TEMPLATES = ['offer' => 'offer_update', 'trade_in' => 'trade_in_update', 'reservation' => 'reservation_update'];

    public function __construct(
        public readonly string $kind,
        public readonly string $car,
        public readonly string $lot,
        public readonly string $update,
        public readonly string $url,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return NotificationPreferences::filter($notifiable, 'offers', ['phone', 'database']);
    }

    public function toPhone(object $notifiable): Message
    {
        $name = explode(' ', (string) ($notifiable->name ?? ''))[0] ?: 'there';

        return new Message(
            self::TEMPLATES[$this->kind],
            [$name, $this->car, $this->lot, $this->update],
            "{$this->lot}, {$this->car}: {$this->update} {$this->url}",
            Message::suffix($this->url),
        );
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'text' => "{$this->car} at {$this->lot}: {$this->update}", 'url' => $this->url];
    }
}
