<?php

namespace App\Domain\Helpdesk\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Helpdesk\Enums\TicketCategory;
use App\Domain\Helpdesk\Enums\TicketPriority;
use App\Domain\Helpdesk\Enums\TicketStatus;
use App\Domain\Helpdesk\Models\SupportTicket;
use App\Domain\Helpdesk\Notifications\SupportTicketAlert;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/** A lot asks LotLink for help: the ticket, its first message and an alert to the admin team. */
class OpenTicket
{
    /**
     * @param  array{subject: string, category: string, priority?: string|null, body: string, vehicle?: string|null}  $data
     */
    public function run(Lot $lot, User $user, array $data, ?UploadedFile $attachment = null): SupportTicket
    {
        $vehicleId = filled($data['vehicle'] ?? null)
            ? Vehicle::withoutGlobalScopes()->where('lot_id', $lot->id)->where('ulid', strtolower((string) $data['vehicle']))->value('id')
            : null;

        $ticket = DB::transaction(function () use ($lot, $user, $data, $attachment, $vehicleId) {
            $ticket = SupportTicket::query()->create([
                'lot_id' => $lot->id,
                'user_id' => $user->id,
                'vehicle_id' => $vehicleId,
                'subject' => trim($data['subject']),
                'category' => TicketCategory::from($data['category']),
                'priority' => TicketPriority::tryFrom((string) ($data['priority'] ?? '')) ?? TicketPriority::Normal,
                'status' => TicketStatus::Open,
                'last_message_at' => now(),
                'lot_read_at' => now(),
            ]);

            $ticket->messages()->create([
                'user_id' => $user->id,
                'from_admin' => false,
                'body' => trim($data['body']),
                ...TicketAttachment::store($ticket, $attachment),
            ]);

            return $ticket;
        });

        Notification::send(SupportTicketAlert::recipients($ticket), new SupportTicketAlert($ticket, 'opened'));

        return $ticket;
    }
}
