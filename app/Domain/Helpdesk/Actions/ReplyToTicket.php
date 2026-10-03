<?php

namespace App\Domain\Helpdesk\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Helpdesk\Enums\TicketStatus;
use App\Domain\Helpdesk\Models\SupportMessage;
use App\Domain\Helpdesk\Models\SupportTicket;
use App\Domain\Helpdesk\Notifications\SupportTicketAlert;
use App\Domain\Helpdesk\Notifications\SupportTicketUpdate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The only way to add a message to a ticket. A seller's reply puts the ticket back with CarYard
 * (reopening a resolved one); an admin's reply puts it with the seller, unless it is an internal
 * note, which the seller never sees and which changes nothing.
 */
class ReplyToTicket
{
    public function fromLot(SupportTicket $ticket, User $user, string $body, ?UploadedFile $attachment = null): SupportMessage
    {
        [$ticket, $message] = DB::transaction(function () use ($ticket, $user, $body, $attachment) {
            $ticket = $this->lock($ticket);

            if ($ticket->status === TicketStatus::Closed) {
                throw ValidationException::withMessages(['body' => 'This ticket is closed. Open a new one and mention '.$ticket->reference.'.']);
            }

            $message = $ticket->messages()->create([
                'user_id' => $user->id,
                'from_admin' => false,
                'body' => trim($body),
                ...TicketAttachment::store($ticket, $attachment),
            ]);

            $ticket->update(['status' => TicketStatus::Open, 'resolved_at' => null, 'last_message_at' => now(), 'lot_read_at' => now(), 'admin_read_at' => null]);

            return [$ticket, $message];
        });

        Notification::send(SupportTicketAlert::recipients($ticket), new SupportTicketAlert($ticket, 'replied'));

        return $message;
    }

    /**
     * @param  UploadedFile|array{attachment_path: string, attachment_name: string|null}|null  $attachment  an upload, or a file the admin panel already stored on the private disk
     * @param  TicketStatus|null  $then  status after the reply; defaults to waiting on the seller
     */
    public function fromAdmin(SupportTicket $ticket, User $admin, string $body, UploadedFile|array|null $attachment = null, bool $internal = false, ?TicketStatus $then = null): SupportMessage
    {
        [$ticket, $message] = DB::transaction(function () use ($ticket, $admin, $body, $attachment, $internal, $then) {
            $ticket = $this->lock($ticket);

            $message = $ticket->messages()->create([
                'user_id' => $admin->id,
                'from_admin' => true,
                'internal' => $internal,
                'body' => trim($body),
                ...(is_array($attachment) ? $attachment : TicketAttachment::store($ticket, $attachment)),
            ]);

            if ($internal) {
                $ticket->update(['admin_read_at' => now()]);
            } else {
                $status = $then ?? TicketStatus::Pending;
                $ticket->update([
                    'status' => $status,
                    'resolved_at' => in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true) ? now() : null,
                    'assigned_to' => $ticket->assigned_to ?? $admin->id,
                    'last_message_at' => now(),
                    'admin_read_at' => now(),
                    'lot_read_at' => null, // unread for the seller until someone opens it
                ]);
            }

            return [$ticket, $message];
        });

        if (! $internal) {
            Notification::send(SupportTicketUpdate::recipients($ticket), new SupportTicketUpdate($ticket, 'replied'));
        }

        return $message;
    }

    private function lock(SupportTicket $ticket): SupportTicket
    {
        return SupportTicket::withoutGlobalScopes()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
    }
}
