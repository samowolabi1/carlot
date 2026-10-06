<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Accounts\Models\User;
use App\Domain\Accounts\Support\Totp;
use App\Domain\Audit\AuditLog;
use App\Domain\Sharing\Support\QrCode;
use App\Domain\Support\Input;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireAdminTwoFactor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** Admin two-factor sign-in: set up an authenticator app once, then a code each session. */
class TwoFactorController extends Controller
{
    public function setup(Request $request): View|RedirectResponse
    {
        $user = $this->admin($request);

        if ($user->two_factor_confirmed_at !== null) {
            return redirect()->route('admin.2fa.challenge');
        }

        // A fresh secret until it's confirmed, kept in the session only.
        $secret = $request->session()->get('admin_2fa_secret') ?? Totp::secret();
        $request->session()->put('admin_2fa_secret', $secret);

        return view('admin.two-factor-setup', [
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'qr' => 'data:image/png;base64,'.base64_encode(QrCode::png(Totp::uri($secret, (string) ($user->email ?? $user->phone)), 260, '#16181D')),
        ]);
    }

    public function confirm(Request $request): View|RedirectResponse
    {
        $user = $this->admin($request);
        $secret = (string) $request->session()->get('admin_2fa_secret');
        $this->throttle($request, $user);

        if ($secret === '' || ! Totp::verify($secret, Input::text($request, 'code'))) {
            throw ValidationException::withMessages(['code' => 'That code didn\'t match. Check the time on your phone and try the newest code.']);
        }

        $codes = Totp::recoveryCodes();
        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode(array_map(fn ($c) => hash('sha256', $c), $codes))),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $request->session()->forget('admin_2fa_secret');
        $request->session()->put(RequireAdminTwoFactor::SESSION_KEY, $user->id);
        AuditLog::record('admin.two_factor_enabled', $user, [], $user);

        return view('admin.two-factor-codes', ['codes' => $codes]);
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        $user = $this->admin($request);

        return $user->two_factor_confirmed_at === null ? redirect()->route('admin.2fa.setup') : view('admin.two-factor-challenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $this->admin($request);
        $this->throttle($request, $user);
        $input = trim(Input::text($request, 'code'));

        $ok = Totp::verify((string) decrypt((string) $user->two_factor_secret), $input) || $this->useRecoveryCode($user, $input);

        if (! $ok) {
            throw ValidationException::withMessages(['code' => 'That code didn\'t work.']);
        }

        RateLimiter::clear($this->key($user));
        $request->session()->regenerate();
        $request->session()->put(RequireAdminTwoFactor::SESSION_KEY, $user->id);

        return redirect()->intended('/admin');
    }

    private function useRecoveryCode(User $user, string $input): bool
    {
        $hashes = json_decode((string) decrypt((string) $user->two_factor_recovery_codes), true) ?: [];
        $hash = hash('sha256', strtolower($input));

        if (! in_array($hash, $hashes, true)) {
            return false;
        }

        $user->forceFill(['two_factor_recovery_codes' => encrypt(json_encode(array_values(array_diff($hashes, [$hash]))))])->save();
        AuditLog::record('admin.recovery_code_used', $user, ['left' => count($hashes) - 1], $user);

        return true;
    }

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);

        return $user;
    }

    private function throttle(Request $request, User $user): void
    {
        if (RateLimiter::tooManyAttempts($this->key($user), 5)) {
            throw ValidationException::withMessages(['code' => 'Too many tries. Wait '.RateLimiter::availableIn($this->key($user)).' seconds.']);
        }
        RateLimiter::hit($this->key($user), 300);
    }

    private function key(User $user): string
    {
        return 'admin-2fa:'.$user->id;
    }
}
