<?php

namespace App\Domain\Deals\Notifications;

use App\Domain\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** To the lot's team: a new offer, trade-in or paid reservation (TDD matrix: in-app for dealers). */
class DealAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $kind, public readonly string $text, public readonly string $url)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return NotificationPreferences::filter($notifiable, 'offers', ['database']);
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'text' => $this->text, 'url' => $this->url];
    }
}
