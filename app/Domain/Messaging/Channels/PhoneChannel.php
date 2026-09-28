<?php

namespace App\Domain\Messaging\Channels;

use App\Domain\Messaging\Message;
use App\Domain\Messaging\Messenger;
use Illuminate\Notifications\Notification;

/**
 * Notification channel for phones: WhatsApp with SMS fallback. Notifications implement
 * toPhone() and, optionally, prefersWhatsApp().
 */
class PhoneChannel
{
    public function __construct(private readonly Messenger $messenger) {}

    public function send(object $notifiable, Notification $notification): void
    {
        $to = $notifiable->routeNotificationFor('phone', $notification) ?? ($notifiable->phone ?? null);

        if (! $to || ! method_exists($notification, 'toPhone')) {
            return;
        }

        /** @var Message|null $message */
        $message = $notification->toPhone($notifiable);

        if ($message === null) {
            return;
        }

        $preferWhatsApp = method_exists($notification, 'prefersWhatsApp') ? $notification->prefersWhatsApp($notifiable) : true;

        $this->messenger->send($to, $message, $preferWhatsApp);
    }
}
