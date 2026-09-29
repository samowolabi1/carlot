<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Accounts\Actions\SignInWithGoogle;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * "Continue with Google" (OAuth via Socialite). Signed out, it signs in or creates the account;
 * signed in, it links Google to the account. Off until GOOGLE_CLIENT_ID is set.
 */
class GoogleController extends Controller
{
    public static function enabled(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public function redirect(Request $request): SymfonyRedirect
    {
        abort_unless(self::enabled(), 404);

        $request->session()->put('google_connect', Auth::check());

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, SignInWithGoogle $signIn): RedirectResponse
    {
        abort_unless(self::enabled(), 404);

        $connecting = (bool) $request->session()->pull('google_connect', false) && Auth::check();
        $back = $connecting ? route('account.security') : route('login');

        if ($request->filled('error')) {
            return redirect($back)->with('error', 'Google sign-in was cancelled.');
        }

        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            Log::warning('Google sign-in failed', ['error' => $e->getMessage()]);

            return redirect($back)->with('error', 'Google sign-in didn\'t finish. Please try again.');
        }

        try {
            $user = $signIn->run($google, $connecting ? $request->user() : null);
        } catch (ValidationException $e) {
            return redirect($back)->with('error', collect($e->errors())->flatten()->first());
        }

        if ($connecting) {
            return redirect()->route('account.security')->with('success', 'Google is connected. You can use it to sign in.');
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }
}
