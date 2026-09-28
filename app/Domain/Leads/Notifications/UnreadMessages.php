<?php

namespace App\Domain\Leads\Notifications;

use App\Domain\Leads\Models\Conversation;
use App\Domain\Messaging\Message;
use App\Domain\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * "You have a new message", by WhatsApp (SMS fallback), when a chat message has gone
 * unread for 10 minutes (TDD notification matrix).
 */
class UnreadMessages extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly string $from,
        public readonly string $snippet,
        public readonly string $url,
    ) {
        $this->onQueue('notifications');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return NotificationPreferences::filter($notifiable, 'messages', ['phone']);
    }

    public function toPhone(object $notifiable): Message
    {
        return new Message(
            'new_message',
            [$this->from, $this->snippet],
            "New message from {$this->from}: \"{$this->snippet}\" Reply: {$this->url}",
            Message::suffix($this->url),
        );
    }
}
