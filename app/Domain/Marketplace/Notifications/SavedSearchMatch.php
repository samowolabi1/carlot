<?php

namespace App\Domain\Marketplace\Notifications;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Marketplace\Models\SavedSearch;
use App\Domain\Messaging\Message;
use App\Domain\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A car matching a saved search was listed or got cheaper (TDD M4, saved_search_match template). */
class SavedSearchMatch extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly SavedSearch $search, public readonly Vehicle $vehicle, public readonly bool $priceDrop = false)
    {
        $this->onQueue('notifications');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channel = match ($this->search->channel) {
            'phone' => ['phone'],
            'mail' => filled($notifiable->email ?? null) ? ['mail'] : [],
            default => [],
        };

        return NotificationPreferences::filter($notifiable, 'alerts', [...$channel, 'database']);
    }

    private function line(): string
    {
        $car = $this->vehicle->title().' ('.$this->vehicle->formattedPrice().') at '.$this->vehicle->lot->name;

        return ($this->priceDrop ? 'Price drop on a match for ' : 'New match for ')."\"{$this->search->name}\": {$car}";
    }

    public function toPhone(object $notifiable): Message
    {
        $url = url($this->vehicle->publicPath());

        return new Message('saved_search_match', [$this->search->name, $this->vehicle->title(), (string) $this->vehicle->formattedPrice(), $this->vehicle->lot->name],
            $this->line().'. '.$url, Message::suffix($url));
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->priceDrop ? 'Price drop on your search' : 'New car for your search')
            ->line($this->line().'.')->action('See the car', url($this->vehicle->publicPath()))
            ->line('Change or turn off this alert under Saved → Searches.');
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'alert', 'text' => $this->line().'.', 'url' => url($this->vehicle->publicPath())];
    }
}
