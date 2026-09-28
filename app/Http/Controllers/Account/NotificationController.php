<?php

namespace App\Http\Controllers\Account;

use App\Domain\Support\NotificationPreferences;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/** The notification centre (design 20) and channel settings per type (TDD M18). */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $items = $user->notifications()->latest()->limit(50)->get();
        $user->unreadNotifications()->update(['read_at' => now()]);

        return Inertia::render('Account/Notifications', [
            'items' => $items->map(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'kind' => $n->data['kind'] ?? 'info',
                'text' => (string) ($n->data['text'] ?? ''),
                'url' => $n->data['url'] ?? null,
                'when' => $n->created_at?->diffForHumans(),
                'new' => $n->read_at === null,
            ]),
        ])->withViewData(['meta' => ['title' => 'Notifications', 'robots' => 'noindex']]);
    }

    public function settings(Request $request): Response
    {
        $isStaff = $request->user()->lots()->exists();

        return Inertia::render('Account/NotificationSettings', [
            'types' => collect(NotificationPreferences::TYPES)
                ->filter(fn ($meta) => $meta[1] === 'all' || ($meta[1] === 'dealers') === $isStaff)
                ->map(fn ($meta, $type) => ['type' => $type, 'label' => $meta[0]])->values(),
            'preferences' => NotificationPreferences::for($request->user()),
            'hasEmail' => filled($request->user()->email),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $types = array_keys(NotificationPreferences::TYPES);
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.phone' => ['boolean'],
            'preferences.*.mail' => ['boolean'],
        ]);

        $preferences = [];
        foreach ($types as $type) {
            $p = $data['preferences'][$type] ?? [];
            $preferences[$type] = ['phone' => (bool) ($p['phone'] ?? true), 'mail' => (bool) ($p['mail'] ?? true)];
        }

        $request->user()->forceFill(['notification_preferences' => $preferences])->save();

        return back()->with('success', 'Saved.');
    }
}
