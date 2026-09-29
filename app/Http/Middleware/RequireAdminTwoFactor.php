<?php

namespace App\Http\Middleware;

use App\Domain\Accounts\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admins must use an authenticator app (TDD M1). Before the admin panel opens: set it up once,
 * then enter a code (or a recovery code) once per session.
 */
class RequireAdminTwoFactor
{
    public const SESSION_KEY = 'admin_two_factor_passed';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! config('lotlink.admin_2fa') || ! $user instanceof User || ! $user->isAdmin()) {
            return $next($request);
        }

        if ($user->two_factor_confirmed_at === null) {
            return redirect()->route('admin.2fa.setup');
        }

        if ($request->session()->get(self::SESSION_KEY) !== $user->id) {
            return redirect()->route('admin.2fa.challenge');
        }

        return $next($request);
    }
}
