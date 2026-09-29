<?php

namespace App\Domain\Trust\Notifications;

use App\Domain\Trust\Enums\VerificationStatus;
use App\Domain\Trust\Models\LotVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the lot owner: the CAC verification was approved or needs another try. */
class VerificationDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly LotVerification $verification)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);

        return (new MailMessage)
            ->subject($this->approved() ? 'Your lot is verified' : 'We could not verify your lot yet')
            ->line($data['text'])
            ->action($this->approved() ? 'Open your dashboard' : 'Try again', $data['url']);
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        $lot = $this->verification->lot;

        return [
            'kind' => 'verification',
            'text' => $this->approved()
                ? "{$lot->name} is verified. Buyers now see the Verified lot badge."
                : "We couldn't verify {$lot->name}: ".($this->verification->notes ?: 'please send the documents again.'),
            'url' => $this->approved() ? route('dealer.dashboard', $lot) : route('dealer.settings', $lot).'#verification',
        ];
    }

    private function approved(): bool
    {
        return $this->verification->status === VerificationStatus::Approved;
    }
}
