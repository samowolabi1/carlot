<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Leads\Events\MessageSent;
use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class SendMessage
{
    /**
     * A chat message from the buyer, the lot, or the system ("Test drive booked"). Photos
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

        broadcast(new MessageSent($message->load('sender'), $conversation))->toOthers();

        return $message;
    }

    private function storePhoto(Conversation $conversation, UploadedFile $photo): string
    {
        $image = (new ImageManager(new Driver, autoOrientation: true))->read($photo->getRealPath())->scaleDown(width: 1600);
        $path = "chat/{$conversation->ulid}/".Str::lower((string) Str::ulid()).'.webp';

        Storage::disk(config('lotlink.media_disk'))->put($path, (string) $image->toWebp(quality: 80), ['visibility' => 'public', 'ContentType' => 'image/webp']);

        return $path;
    }
}
