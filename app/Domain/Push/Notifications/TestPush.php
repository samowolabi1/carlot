<?php

namespace App\Domain\Push\Notifications;

use Illuminate\Notifications\Notification;

/** "Send a test" on the notification settings page. Sent now, so the person sees it arrive. */
class TestPush extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['push'];
    }

    /** @return array{title: string, body: string, url: string, tag: string} */
    public function toPush(object $notifiable): array
    {
        return ['title' => 'LotLink', 'body' => 'Push notifications work on this device.', 'url' => route('notifications.settings'), 'tag' => 'test'];
    }
}
