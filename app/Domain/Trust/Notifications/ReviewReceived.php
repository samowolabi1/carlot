<?php

namespace App\Domain\Trust\Notifications;

use App\Domain\Support\NotificationPreferences;
use App\Domain\Trust\Models\Review;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the seller's owner and managers: a buyer reviewed their visit (in-app and email). */
class ReviewReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Review $review)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $channels = filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];

        return NotificationPreferences::filter($notifiable, 'reviews', $channels);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);

        return (new MailMessage)->subject("New {$this->review->rating}-star review")->line($data['text'])->action('Read and reply', $data['url']);
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        $lot = $this->review->lot;
        $excerpt = $this->review->body ? ': "'.str($this->review->body)->limit(80).'"' : '.';

        return [
            'kind' => 'review',
            'text' => "{$this->review->authorName()} gave {$lot->name} {$this->review->rating} ".str('star')->plural($this->review->rating).$excerpt,
            'url' => route('dealer.reviews', $lot),
        ];
    }
}
