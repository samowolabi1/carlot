<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\Models\User;
use App\Domain\Engagement\Models\EngagementMessage;
use App\Domain\Support\NotificationPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The links in engagement messages: `/e/{ulid}` records the click and goes on; the signed unsubscribe link turns the email off. */
class EngagementController extends Controller
{
    public function click(EngagementMessage $message): RedirectResponse
    {
        if ($message->clicked_at === null) {
            $message->forceFill(['clicked_at' => now()])->save();
        }

        return redirect()->away($message->url ?: route('dealer.home'));
    }

    public function unsubscribe(Request $request, User $user, string $type): Response
    {
        abort_unless(in_array($type, ['news', 'nudges'], true), 404);

        $prefs = $user->notification_preferences ?? [];
        $prefs[$type] = [...NotificationPreferences::for($user)[$type], 'mail' => false];
        $user->forceFill(['notification_preferences' => $prefs])->save();

        return Inertia::render('Engagement/Unsubscribed', [
            'what' => NotificationPreferences::TYPES[$type][0],
        ])->withViewData(['meta' => ['title' => 'Unsubscribed', 'robots' => 'noindex']]);
    }
}
