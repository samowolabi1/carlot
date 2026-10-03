<?php

namespace App\Domain\Helpdesk\Notifications;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminArea;
use App\Domain\Helpdesk\Enums\TicketPriority;
use App\Domain\Helpdesk\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the CarYard team: a seller opened, replied to or reopened a ticket (in-app and email). */
class SupportTicketAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  'opened'|'replied'|'reopened'  $event */
    public function __construct(public readonly SupportTicket $ticket, public readonly string $event)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /**
     * The assigned admin, or every admin when nobody has picked it up.
     *
     * @return Collection<int, User>
     */
    public static function recipients(SupportTicket $ticket): Collection
    {
        $assignee = $ticket->assigned_to ? User::query()->adminsFor(AdminArea::Support)->whereKey($ticket->assigned_to)->get() : new Collection;

        return $assignee->isNotEmpty() ? $assignee : User::query()->adminsFor(AdminArea::Support)->get();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);
        $urgent = in_array($this->ticket->priority, [TicketPriority::High, TicketPriority::Urgent], true) ? "[{$this->ticket->priority->short()}] " : '';

        return (new MailMessage)
            ->subject("{$urgent}{$this->ticket->reference}: {$this->ticket->subject}")
            ->line($data['text'])
            ->action('Open the ticket', $data['url']);
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        $lot = $this->ticket->lot()->withoutGlobalScopes()->withTrashed()->first();
        $what = match ($this->event) {
            'opened' => 'opened a support ticket',
            'reopened' => 'reopened',
            default => 'replied on',
        };

        return [
            'kind' => 'support',
            'text' => ($lot->name ?? 'A seller')." {$what} {$this->ticket->reference}: {$this->ticket->subject}",
            'url' => route('filament.admin.resources.support-tickets.view', $this->ticket->ulid),
        ];
    }
}
