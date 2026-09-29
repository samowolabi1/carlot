<?php

namespace App\Domain\Engagement\Notifications;

use App\Domain\Engagement\Models\EngagementMessage;
use App\Domain\Messaging\Message;
use App\Domain\Support\NotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * A LotLink message to a lot owner or manager: an admin broadcast ("news") or an automated nudge
 * ("nudges"). Always in the notification centre; email, push and WhatsApp as chosen and as the
 * person's settings allow. Links go through the message's tracked `/e/{ulid}`; emails carry an unsubscribe link.
 */
class EngagementNotice extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'news'|'nudges'  $type
     * @param  list<string>  $lines
     * @param  list<string>  $channels  mail, push, whatsapp (in-app always)
     */
    public function __construct(
        public readonly EngagementMessage $message,
        public readonly string $type,
        public readonly string $subject,
        public readonly array $lines,
        public readonly string $ctaLabel,
        public readonly array $channels,
    ) {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $wanted = ['database', ...(in_array('mail', $this->channels, true) && filled($notifiable->email ?? null) ? ['mail'] : []),
            ...(in_array('whatsapp', $this->channels, true) && filled($notifiable->phone ?? null) ? ['phone'] : [])];
        $allowed = NotificationPreferences::filter($notifiable, $this->type, $wanted);

        // Push is added by filter() for people with a device; keep it only if this message goes by push.
        return array_values(in_array('push', $this->channels, true) ? $allowed : array_diff($allowed, ['push']));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = trim(explode(' ', (string) ($notifiable->name ?? ''))[0]);
        $mail = (new MailMessage)->subject($this->subject)->greeting($name !== '' ? "Hi {$name}," : 'Hi,');
        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        $unsubscribe = URL::signedRoute('engagement.unsubscribe', ['user' => $notifiable->ulid ?? '', 'type' => $this->type]);

        return $mail->action($this->ctaLabel, $this->message->link())
            ->salutation('The LotLink team')
            ->line($this->type === 'news'
                ? "Don't want LotLink news by email? [Unsubscribe]({$unsubscribe})."
                : "Don't want these reminders by email? [Turn them off]({$unsubscribe}).");
    }

    /** @return array{title: string, body: string, url: string, tag: string} */
    public function toPush(object $notifiable): array
    {
        return ['title' => $this->type === 'news' ? 'LotLink' : 'Your lot', 'body' => $this->subject, 'url' => $this->message->link(), 'tag' => 'engagement-'.$this->message->ulid];
    }

    public function toPhone(object $notifiable): Message
    {
        $name = trim(explode(' ', (string) ($notifiable->name ?? ''))[0]) ?: 'there';
        $link = $this->message->link();

        return new Message('lot_announcement', [$name, $this->subject], "LotLink: {$this->subject} {$link}", Message::suffix($link));
    }

    /** @return array{kind: string, text: string, url: string} */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->type === 'news' ? 'news' : 'nudge', 'text' => $this->subject, 'url' => $this->message->link()];
    }
}
