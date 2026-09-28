<?php

namespace App\Domain\Billing\Notifications;

use App\Domain\Lots\Models\Lot;
use App\Domain\Messaging\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** To the lot owner: trial ending, payment failed, plan changed. WhatsApp, SMS fallback. */
class BillingNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Lot $lot, public readonly string $text)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['phone', 'database'];
    }

    public function toPhone(object $notifiable): Message
    {
        $url = route('dealer.billing', $this->lot->slug);

        return new Message('billing_update', [$this->lot->name, $this->text], "{$this->lot->name}: {$this->text} Billing: {$url}", Message::suffix($url));
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return ['lot' => $this->lot->slug, 'text' => $this->text, 'url' => route('dealer.billing', $this->lot->slug)];
    }
}
