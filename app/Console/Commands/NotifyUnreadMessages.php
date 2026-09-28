<?php

namespace App\Console\Commands;

use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;
use App\Domain\Leads\Notifications\UnreadMessages;
use App\Domain\Lots\Models\Lot;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Every 5 minutes: a chat message unread for 10 minutes goes to the other side by
 * WhatsApp (SMS fallback), once per batch of unread messages (TDD notification matrix).
 */
class NotifyUnreadMessages extends Command
{
    protected $signature = 'chat:notify-unread';

    protected $description = 'Send WhatsApp/SMS for chat messages unread after 10 minutes';

    public function handle(): int
    {
        $sent = 0;

        Conversation::query()->where('last_message_at', '<=', now()->subMinutes(10))->where('last_message_at', '>', now()->subDays(2))
            ->each(function (Conversation $conversation) use (&$sent): void {
                $lead = Lead::withoutGlobalScopes()->with(['customer', 'assignee', 'vehicle.make', 'vehicle.model'])->find($conversation->lead_id);
                $lot = $lead ? Lot::with('owner')->find($lead->lot_id) : null;
                if ($lead === null || $lot === null) {
                    return;
                }

                foreach ([Message::CUSTOMER, Message::LOT] as $reader) {
                    $message = $this->oldestUnnotified($conversation, $reader);
                    if ($message === null || $message->created_at->gt(now()->subMinutes(10))) {
                        continue;
                    }

                    $snippet = Str::limit($message->body ?: 'Photo', 80);
                    if ($reader === Message::CUSTOMER && $lead->customer) {
                        $lead->customer->notify(new UnreadMessages($conversation, $lot->name, $snippet, route('conversations.show', $conversation)));
                        $conversation->forceFill(['customer_notified_at' => now()])->save();
                        $sent++;
                    } elseif ($reader === Message::LOT && ($to = $lead->assignee ?? $lot->owner)) {
                        $to->notify(new UnreadMessages($conversation, $lead->customer->name ?? 'a buyer', $snippet, route('dealer.leads.show', [$lot->slug, $lead->ulid])));
                        $conversation->forceFill(['lot_notified_at' => now()])->save();
                        $sent++;
                    }
                }
            });

        $this->info("Sent {$sent} unread-message alerts.");

        return self::SUCCESS;
    }

    /** The first message this side hasn't read and hasn't been told about. */
    private function oldestUnnotified(Conversation $conversation, string $reader): ?Message
    {
        $readAt = $reader === Message::CUSTOMER ? $conversation->customer_read_at : $conversation->lot_read_at;
        $notifiedAt = $reader === Message::CUSTOMER ? $conversation->customer_notified_at : $conversation->lot_notified_at;
        $since = max($readAt?->getTimestamp() ?? 0, $notifiedAt?->getTimestamp() ?? 0);

        return $conversation->messages()
            ->where('side', $reader === Message::CUSTOMER ? Message::LOT : Message::CUSTOMER)
            ->where('created_at', '>', date('Y-m-d H:i:s', $since))
            ->orderBy('id')
            ->first();
    }
}
