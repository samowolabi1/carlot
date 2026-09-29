<?php

namespace App\Http\Controllers\Account;

use App\Domain\Accounts\Actions\SetPassword;
use App\Domain\Accounts\Actions\SignInWithGoogle;
use App\Domain\Support\PhoneNumber;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Account → Sign-in and security: WhatsApp/email codes (always on), an optional password, Google. */
class SecurityController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Account/Security', [
            'phone' => $user->phone ? PhoneNumber::display($user->phone) : null,
            'email' => $user->email,
            'hasPassword' => $user->password !== null,
            'passwordChanged' => $user->password_changed_at?->timezone(config('lotlink.timezone', 'Africa/Lagos'))->format('j M Y'),
            'google' => ['enabled' => GoogleController::enabled(), 'connected' => $user->google_id !== null],
            'isAdmin' => $user->isAdmin(),
        ])->withViewData(['meta' => ['title' => 'Sign-in and security', 'robots' => 'noindex']]);
    }

    public function updatePassword(Request $request, SetPassword $setPassword): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => [$user->password !== null ? 'required' : 'nullable', 'string', 'max:200'],
            'password' => ['required', 'string', 'max:200', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $first = $user->password === null;
        $setPassword->run($user, $data['password'], $data['current_password'] ?? null);
        Auth::login($user, remember: true); // keep this device signed in with the new remember token

        return back()->with('success', $first ? 'Password added. You can now sign in with it too.' : 'Password changed.');
    }

    public function destroyPassword(Request $request, SetPassword $setPassword): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:200']]);

        $setPassword->remove($request->user(), $data['current_password']);
        Auth::login($request->user(), remember: true);

        return back()->with('success', 'Password removed. Sign in with a one-time code.');
    }

    public function disconnectGoogle(Request $request, SignInWithGoogle $google): RedirectResponse
    {
        $google->disconnect($request->user());

        return back()->with('success', 'Google is disconnected.');
    }
}
