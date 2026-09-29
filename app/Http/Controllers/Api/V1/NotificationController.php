<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/** The notification centre: the same items as the bell on the web. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $user->notifications()->latest()->limit(50)->get()->map(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'kind' => $n->data['kind'] ?? 'info',
                'text' => (string) ($n->data['text'] ?? ''),
                'url' => $n->data['url'] ?? null,
                'created_at' => $n->created_at?->toIso8601String(),
                'read' => $n->read_at !== null,
            ]),
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    /** Mark everything read (after the list has been seen). */
    public function read(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread' => 0]);
    }
}
