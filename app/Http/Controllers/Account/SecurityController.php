<?php

namespace App\Http\Controllers\Account;

use App\Domain\Accounts\Actions\SetPassword;
use App\Domain\Accounts\Actions\SignInWithGoogle;
use App\Domain\Audit\AuditLog;
use App\Domain\Support\Fields;
use App\Domain\Support\PhoneNumber;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Controller;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

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
            // Phones signed in to the LotLink app (API tokens).
            'apps' => $user->tokens()->latest()->get()->map(fn (PersonalAccessToken $t) => [
                'id' => $t->getKey(),
                'name' => $t->name,
                'last_used' => $t->last_used_at?->diffForHumans() ?? 'Not used yet',
                'since' => $t->created_at?->timezone((string) config('lotlink.timezone'))->format('j M Y'),
            ])->values(),
        ])->withViewData(['meta' => ['title' => 'Sign-in and security', 'robots' => 'noindex']]);
    }

    public function updatePassword(Request $request, SetPassword $setPassword): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => [$user->password !== null ? 'required' : 'nullable', 'string', 'max:200'],
            'password' => Fields::newPassword(),
        ]);

        $first = $user->password === null;
        $setPassword->run($user, $data['password'], $data['current_password'] ?? null);
        $this->staySignedIn($request);

        return back()->with('success', $first ? 'Password added. You can now sign in with it too.' : 'Password changed.');
    }

    public function destroyPassword(Request $request, SetPassword $setPassword): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:200']]);

        $setPassword->remove($request->user(), $data['current_password']);
        $this->staySignedIn($request);

        return back()->with('success', 'Password removed. Sign in with a one-time code.');
    }

    /**
     * The password change gave the account a new remember token (signing out other devices); if this
     * device was kept signed in, give it a fresh cookie so it stays that way.
     */
    private function staySignedIn(Request $request): void
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        Auth::login($request->user(), remember: $request->cookies->has($guard->getRecallerName()));
    }

    /** Sign the app out on one phone. */
    public function revokeApp(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->whereKey($token)->delete();
        AuditLog::record('account.app_signed_out', $request->user(), [], $request->user());

        return back()->with('success', 'That phone is signed out of the app.');
    }

    public function disconnectGoogle(Request $request, SignInWithGoogle $google): RedirectResponse
    {
        $google->disconnect($request->user());

        return back()->with('success', 'Google is disconnected.');
    }
}
