<?php

namespace App\Domain\Helpdesk\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Helpdesk\Enums\TicketStatus;
use App\Domain\Helpdesk\Models\SupportTicket;
use App\Domain\Helpdesk\Notifications\SupportTicketAlert;
use App\Domain\Helpdesk\Notifications\SupportTicketUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Status changes outside a reply. Lots can mark their ticket solved or reopen a resolved one;
 * admins can set any status and assign tickets. Every change is audit-logged.
 */
class ChangeTicketStatus
{
    public function byLot(SupportTicket $ticket, User $user, TicketStatus $to): SupportTicket
    {
        $allowed = match ($to) {
            TicketStatus::Resolved => $ticket->status->isActive(),
            TicketStatus::Open => $ticket->status === TicketStatus::Resolved,
            default => false,
        };

        if (! $allowed) {
            throw ValidationException::withMessages(['status' => $ticket->status === TicketStatus::Closed
                ? 'This ticket is closed. Open a new one if you still need help.'
                : 'That change isn\'t possible for this ticket.']);
        }

        $ticket = $this->change($ticket, $user, $to);

        if ($to === TicketStatus::Open) {
            Notification::send(SupportTicketAlert::recipients($ticket), new SupportTicketAlert($ticket, 'reopened'));
        }

        return $ticket;
    }

    public function byAdmin(SupportTicket $ticket, User $admin, TicketStatus $to): SupportTicket
    {
        $was = $ticket->status;
        $ticket = $this->change($ticket, $admin, $to);

        if ($was !== $to && in_array($to, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
            $ticket->forceFill(['lot_read_at' => null])->save();
            Notification::send(SupportTicketUpdate::recipients($ticket), new SupportTicketUpdate($ticket, $to->value));
        }

        return $ticket;
    }

    public function assign(SupportTicket $ticket, User $admin, ?User $to): SupportTicket
    {
        $before = $ticket->assigned_to;
        $ticket->update(['assigned_to' => $to?->id]);
        AuditLog::record('support.assigned', $ticket, ['before' => $before, 'after' => $to?->id], $admin, $ticket->lot_id);

        return $ticket;
    }

    private function change(SupportTicket $ticket, User $by, TicketStatus $to): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $by, $to) {
            $ticket = SupportTicket::withoutGlobalScopes()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $before = $ticket->status;

            if ($before === $to) {
                return $ticket;
            }

            $ticket->update([
                'status' => $to,
                'resolved_at' => in_array($to, [TicketStatus::Resolved, TicketStatus::Closed], true) ? now() : null,
            ]);
            AuditLog::record('support.status_changed', $ticket, ['before' => $before->value, 'after' => $to->value], $by, $ticket->lot_id);

            return $ticket;
        });
    }
}
