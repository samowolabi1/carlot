<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Accounts\Actions\LogInWithPassword;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Email (or WhatsApp number) and password, for accounts that added one. */
class PasswordLoginController extends Controller
{
    public const ATTEMPTS = 5;

    public function store(Request $request, LogInWithPassword $logIn): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        // Five tries a minute per account and address, on top of the route's per-IP limit.
        $key = 'password-login:'.sha1(Str::lower(trim($data['login']))).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            throw ValidationException::withMessages(['login' => 'Too many tries. Wait '.RateLimiter::availableIn($key).' seconds, or sign in with a code.']);
        }

        try {
            $user = $logIn->run($data['login'], $data['password']);
        } catch (ValidationException $e) {
            RateLimiter::hit($key, 60);
            throw $e;
        }

        RateLimiter::clear($key);
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }
}
