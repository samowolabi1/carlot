<?php

namespace App\Http\Controllers\Account;

use App\Domain\Push\Actions\SavePushSubscription;
use App\Domain\Push\Notifications\TestPush;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Push notifications on this device: turn on (the browser's subscription), off, test, and forget other devices. */
class PushSubscriptionController extends Controller
{
    public function store(Request $request, SavePushSubscription $save): Response
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url:https', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'max:20'],
        ]);

        $save->run($request->user(), $data, $request->userAgent());
        // Remembered so signing out on this device also stops its pushes.
        $request->session()->put('push_endpoint', $data['endpoint']);

        return response()->noContent();
    }

    public function destroy(Request $request, SavePushSubscription $save): Response
    {
        $endpoint = (string) $request->validate(['endpoint' => ['required', 'string', 'max:2000']])['endpoint'];

        $request->user()->pushSubscriptions()->where('endpoint_hash', hash('sha256', $endpoint))->delete();
        $request->session()->forget('push_endpoint');

        return response()->noContent();
    }

    /** "Remove" next to another device in the list. */
    public function forget(Request $request, string $device): RedirectResponse
    {
        $request->user()->pushSubscriptions()->where('endpoint_hash', $device)->delete();

        return back()->with('success', 'That device won\'t get push notifications any more.');
    }

    public function test(Request $request): RedirectResponse
    {
        if (! $request->user()->pushSubscriptions()->exists()) {
            return back()->with('error', 'Turn on push notifications on this device first.');
        }

        $request->user()->notifyNow(new TestPush);

        return back()->with('success', 'Test sent. It should appear in a few seconds.');
    }
}
