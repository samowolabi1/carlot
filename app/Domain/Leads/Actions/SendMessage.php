<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Leads\Events\MessageSent;
use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;
use App\Domain\Lots\Models\Lot;
use App\Domain\Push\Notifications\ChatMessagePush;
use App\Domain\Support\Realtime;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class SendMessage
{
    /**
     * A chat message from the buyer, the seller, or the system ("Test drive booked"). Photos
     * are re-encoded to WebP, which also strips location data from phone pictures.
     */
    public function run(Conversation $conversation, ?User $sender, string $side, string $body, ?UploadedFile $photo = null): Message
    {
        $path = $photo ? $this->storePhoto($conversation, $photo) : null;

        $message = DB::transaction(function () use ($conversation, $sender, $side, $body, $path): Message {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $sender?->id,
                'side' => $side,
                'body' => trim($body),
                'attachment_path' => $path,
            ]);

            $conversation->forceFill([
                'last_message_at' => $message->created_at,
                // Sending counts as having read everything so far.
                ...match ($side) {
                    Message::CUSTOMER => ['customer_read_at' => $message->created_at],
                    Message::LOT => ['lot_read_at' => $message->created_at],
                    default => [],
                },
            ])->save();

            $lead = Lead::withoutGlobalScopes()->findOrFail($conversation->lead_id);
            $lead->last_activity_at = now();
            if ($side === Message::LOT) {
                $lead->first_response_at ??= now();
                if ($lead->stage === LeadStage::New) {
                    $lead->stage = LeadStage::Contacted;
                }
                $lead->assigned_to ??= $sender?->id;
            }
            $lead->save();

            return $message;
        });

        Realtime::send(new MessageSent($message->load('sender'), $conversation), toOthers: true);
        $this->push($conversation, $message);

        return $message;
    }

    /**
     * A push to the other side's devices straight away (buyer ↔ the assigned salesperson, else the
     * owner). System lines ("Test drive booked") aren't pushed: their own notifications cover them.
     */
    private function push(Conversation $conversation, Message $message): void
    {
        if (! in_array($message->side, [Message::CUSTOMER, Message::LOT], true)) {
            return;
        }

        $lead = Lead::withoutGlobalScopes()->with(['customer', 'assignee'])->find($conversation->lead_id);
        $lot = $lead ? Lot::withoutGlobalScopes()->with('owner')->find($lead->lot_id) : null;
        if ($lead === null || $lot === null) {
            return;
        }

        $snippet = Str::limit($message->body ?: 'Sent a photo', 120);
        if ($message->side === Message::CUSTOMER) {
            // A brand-new lead's opening message is already in the "New lead" alert.
            if ($lead->created_at?->gt(now()->subMinute()) && $conversation->messages()->count() === 1) {
                return;
            }
            $to = $lead->assignee ?? $lot->owner;
            $to?->notify(new ChatMessagePush($lead->customer->name ?? 'A buyer', $snippet, route('dealer.leads.show', [$lot->slug, $lead->ulid]), $conversation->ulid));
        } else {
            $lead->customer?->notify(new ChatMessagePush($lot->name, $snippet, route('conversations.show', $conversation), $conversation->ulid));
        }
    }

    private function storePhoto(Conversation $conversation, UploadedFile $photo): string
    {
        $image = (new ImageManager(new Driver, autoOrientation: true))->read($photo->getRealPath())->scaleDown(width: 1600);
        $path = "chat/{$conversation->ulid}/".Str::lower((string) Str::ulid()).'.webp';

        Storage::disk(config('lotlink.media_disk'))->put($path, (string) $image->toWebp(quality: 80), ['visibility' => 'public', 'ContentType' => 'image/webp']);

        return $path;
    }
}
