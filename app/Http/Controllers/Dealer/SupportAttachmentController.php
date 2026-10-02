<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Admin\AdminArea;
use App\Domain\Helpdesk\Models\SupportMessage;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A ticket attachment, for the lot's staff (not internal notes) and LotLink admins. */
class SupportAttachmentController extends Controller
{
    public function __invoke(Request $request, SupportMessage $message): StreamedResponse
    {
        $user = $request->user();
        $ticket = $message->ticket;
        $lot = Lot::withoutGlobalScopes()->findOrFail($ticket->lot_id);

        $allowed = $user->adminCan(AdminArea::Support) || (! $message->internal && $user->roleIn($lot) !== null);
        abort_unless($allowed, 404);

        $disk = Storage::disk(SupportMessage::DISK);
        abort_unless($message->attachment_path && $disk->exists($message->attachment_path), 404);

        return $disk->response($message->attachment_path, $message->attachment_name ?? basename($message->attachment_path), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
