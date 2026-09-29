<?php

namespace App\Domain\Push\Notifications;

use App\Domain\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A chat message, pushed straight away to the other side's devices (WhatsApp still follows after
 * 10 minutes unread: `UnreadMessages`). Push only; skipped when "Chat messages" push is off.
 */
class ChatMessagePush extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $from, public readonly string $snippet, public readonly string $url, public readonly string $thread)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return array_values(array_intersect(NotificationPreferences::filter($notifiable, 'messages', []), ['push']));
    }

    /** @return array{title: string, body: string, url: string, tag: string} */
    public function toPush(object $notifiable): array
    {
        return ['title' => $this->from, 'body' => $this->snippet, 'url' => $this->url, 'tag' => "chat-{$this->thread}"];
    }
}
