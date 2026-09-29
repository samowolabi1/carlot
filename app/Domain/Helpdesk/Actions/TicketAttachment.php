<?php

namespace App\Domain\Helpdesk\Actions;

use App\Domain\Helpdesk\Models\SupportMessage;
use App\Domain\Helpdesk\Models\SupportTicket;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/** Screenshots and documents on tickets: private disk, random names, the original name kept for display. */
final class TicketAttachment
{
    public const MAX_KB = 10240;

    public const MIMES = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    /** @return array{attachment_path?: string, attachment_name?: string} */
    public static function store(SupportTicket $ticket, ?UploadedFile $file): array
    {
        if ($file === null) {
            return [];
        }

        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
        $path = $file->storeAs("support/{$ticket->ulid}", Str::random(24).'.'.$extension, ['disk' => SupportMessage::DISK]);

        return [
            'attachment_path' => (string) $path,
            'attachment_name' => Str::limit(basename($file->getClientOriginalName()), 150, ''),
        ];
    }
}
