<?php

namespace App\Domain\Helpdesk\Notifications;

use App\Domain\Accounts\Models\User;
use App\Domain\Helpdesk\Models\SupportTicket;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the seller: CarYard replied, or resolved or closed their ticket (in-app and email). */
class SupportTicketUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  'replied'|'resolved'|'closed'|string  $event */
    public function __construct(public readonly SupportTicket $ticket, public readonly string $event)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /**
     * Whoever opened it, plus the seller's owner and managers.
     *
     * @return Collection<int, User>
     */
    public static function recipients(SupportTicket $ticket): Collection
    {
        $lot = Lot::withoutGlobalScopes()->find($ticket->lot_id);
        $managers = $lot ? $lot->members()->wherePivotIn('role', [LotRole::Owner->value, LotRole::Manager->value])->get() : new Collection;
        $opener = $ticket->user_id ? User::query()->whereKey($ticket->user_id)->get() : new Collection;

        return $managers->merge($opener)->unique('id')->values();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);

        return (new MailMessage)->subject("{$this->ticket->reference}: {$this->ticket->subject}")->line($data['text'])->action('Open the ticket', $data['url']);
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        $what = match ($this->event) {
            'resolved' => 'marked your ticket resolved. Reply if you still need help',
            'closed' => 'closed your ticket',
            default => 'replied to your ticket',
        };

        return [
            'kind' => 'support',
            'text' => "CarYard Support {$what}: {$this->ticket->reference} {$this->ticket->subject}",
            'url' => route('dealer.support.show', [Lot::withoutGlobalScopes()->findOrFail($this->ticket->lot_id)->slug, $this->ticket->ulid]),
        ];
    }
}
