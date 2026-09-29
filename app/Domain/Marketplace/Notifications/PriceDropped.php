<?php

namespace App\Domain\Marketplace\Notifications;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Messaging\Message;
use App\Domain\Support\Money;
use App\Domain\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Price drop: 2017 RAV4 XLE is now ₦14,200,000 (₦300,000 less)" to buyers who saved the car. */
class PriceDropped extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Vehicle $vehicle, public readonly int $oldPrice, public readonly int $newPrice)
    {
        $this->onQueue('notifications');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return NotificationPreferences::filter($notifiable, 'alerts', ['phone', 'mail', 'database']);
    }

    private function line(): string
    {
        return 'Price drop: '.$this->vehicle->title().' is now '.Money::format($this->newPrice, $this->vehicle->currency)
            .' ('.Money::format($this->oldPrice - $this->newPrice, $this->vehicle->currency).' less) at '.$this->vehicle->lot->name;
    }

    public function toPhone(object $notifiable): Message
    {
        $url = url($this->vehicle->publicPath());

        return new Message('price_drop', [$this->vehicle->title(), (string) Money::format($this->newPrice, $this->vehicle->currency), (string) Money::format($this->oldPrice - $this->newPrice, $this->vehicle->currency), $this->vehicle->lot->name],
            $this->line().'. '.$url, Message::suffix($url));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Price drop on a car you saved')->line($this->line().'.')->action('See the car', url($this->vehicle->publicPath()));
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'price_drop', 'text' => $this->line().'.', 'url' => url($this->vehicle->publicPath())];
    }
}
