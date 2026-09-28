<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** New accounts (created by phone sign-in) must add their name before continuing. */
class EnsureProfileComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && blank($request->user()->name)) {
            return redirect()->guest(route('profile.name'));
        }

        return $next($request);
    }
}
