<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Accounts\Actions\SetPassword;
use App\Domain\Accounts\Models\User;
use App\Domain\Support\Fields;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Forgot your password?" by email (Laravel's password broker: hashed one-hour tokens, one email a
 * minute). The answer never says whether an account exists. People with no email on their account
 * sign in with a WhatsApp code and set a new password in Account → Sign-in and security.
 */
class PasswordResetController extends Controller
{
    public const SENT = 'If that email has a CarYard account, we\'ve sent a link to reset the password. Check your inbox and spam folder.';

    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ForgotPassword', ['email' => (string) $request->query('email', '')])
            ->withViewData(['meta' => ['title' => 'Reset your password', 'robots' => 'noindex']]);
    }

    public function store(Request $request): RedirectResponse
    {
        $email = Str::lower(trim((string) $request->validate(['email' => Fields::email()])['email']));

        $status = Password::sendResetLink(['email' => $email]);

        // Unknown emails get the same answer; only a too-soon repeat is told to wait.
        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages(['email' => 'We just sent a link. Wait a minute before asking for another.']);
        }

        return back()->with('status', self::SENT); // shown inline on the page, not as a toast
    }

    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', ['token' => $token, 'email' => (string) $request->query('email', '')])
            ->withViewData(['meta' => ['title' => 'Choose a new password', 'robots' => 'noindex']]);
    }

    public function update(Request $request, SetPassword $setPassword): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'email' => Fields::email(),
            'password' => Fields::newPassword(),
        ]);

        $user = null;
        $status = Password::reset(
            ['email' => Str::lower(trim($data['email'])), 'token' => $data['token'], 'password' => $data['password']],
            function (User $account, string $password) use ($setPassword, &$user): void {
                $setPassword->reset($account, $password);
                $user = $account;
            },
        );

        if ($status !== Password::PASSWORD_RESET || ! $user instanceof User) {
            throw ValidationException::withMessages(['email' => 'This reset link is invalid or has expired. Ask for a new one.']);
        }

        // They just proved they own the email: sign them in on this device (not remembered).
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('home'))->with('success', 'Password changed. You\'re signed in.');
    }
}
