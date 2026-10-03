<?php

namespace App\Http\Middleware;

use App\Domain\Admin\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records when a signed-in person last used CarYard (web or app), at most every 15 minutes,
 * for the "haven't signed in for a while" emails and admin reports. A plain update: no events.
 */
class TouchLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user() ?? $request->user('sanctum');
        // An admin's "Log in as" isn't the person using CarYard.
        if ($user !== null && $request->hasSession() && Impersonation::active()) {
            return $response;
        }
        if ($user !== null && ($user->last_seen_at === null || $user->last_seen_at->lt(now()->subMinutes(15)))) {
            $user->newQuery()->whereKey($user->getKey())->update(['last_seen_at' => now()]);
            $user->last_seen_at = now();
        }

        return $response;
    }
}
