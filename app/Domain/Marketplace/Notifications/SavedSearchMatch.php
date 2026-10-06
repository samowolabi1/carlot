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

    /** The search by id and the two things the message needs: buyers can delete a search while the alert is queued. */
    public readonly int $searchId;

    public readonly string $searchName;

    public readonly ?string $channel;

    public function __construct(SavedSearch $search, public readonly Vehicle $vehicle, public readonly bool $priceDrop = false)
    {
        $this->searchId = (int) $search->getKey();
        $this->searchName = (string) $search->name;
        $this->channel = $search->channel;
        $this->onQueue('notifications');
    }

    /** A search deleted in the meantime gets no alert. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return SavedSearch::query()->whereKey($this->searchId)->exists();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channel = match ($this->channel) {
            'phone' => ['phone'],
            'mail' => filled($notifiable->email ?? null) ? ['mail'] : [],
            default => [],
        };

        return NotificationPreferences::filter($notifiable, 'alerts', [...$channel, 'database']);
    }

    private function line(): string
    {
        $car = $this->vehicle->title().' ('.$this->vehicle->formattedPrice().') at '.$this->vehicle->lot->name;

        return ($this->priceDrop ? 'Price drop on a match for ' : 'New match for ')."\"{$this->searchName}\": {$car}";
    }

    public function toPhone(object $notifiable): Message
    {
        $url = url($this->vehicle->publicPath());

        return new Message('saved_search_match', [$this->searchName, $this->vehicle->title(), (string) $this->vehicle->formattedPrice(), $this->vehicle->lot->name],
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
