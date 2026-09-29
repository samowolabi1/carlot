<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Accounts\Actions\SendOtp;
use App\Domain\Accounts\Actions\VerifyOtp;
use App\Domain\Accounts\Exceptions\OtpException;
use App\Domain\Push\Models\PushSubscription;
use App\Domain\Support\PhoneNumber;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Sign in or sign up with a one-time code. People pick how: their WhatsApp number or their email
 * address (both quick, and neither costs them anything). New here: this creates the account.
 * The same page offers "Continue with Google" and, for accounts that added one, a password.
 */
class OtpLoginController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Login', [
            'method' => in_array($request->query('method'), ['email', 'password'], true) ? $request->query('method') : 'whatsapp',
            'google' => GoogleController::enabled(),
        ]);
    }

    public function store(Request $request, SendOtp $sendOtp): RedirectResponse
    {
        $method = $request->input('method') === 'email' ? 'email' : 'whatsapp';

        if ($method === 'email') {
            $email = Str::lower(trim((string) $request->validate(['email' => ['required', 'email:rfc', 'max:190']])['email']));

            try {
                $sendOtp->toEmail($email);
            } catch (OtpException $e) {
                throw ValidationException::withMessages(['email' => $e->getMessage()]);
            }

            $request->session()->put(['otp_method' => 'email', 'otp_to' => $email, 'otp_channel' => 'email', 'otp_remember' => $request->boolean('remember')]);

            return redirect()->route('login.verify');
        }

        $request->validate(['phone' => ['required', 'string', 'max:32']]);

        try {
            $phone = PhoneNumber::normalize($request->string('phone'));
            ['channel' => $channel] = $sendOtp->run($phone);
        } catch (InvalidArgumentException|OtpException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }

        $request->session()->put(['otp_method' => 'whatsapp', 'otp_to' => $phone, 'otp_channel' => $channel, 'otp_remember' => $request->boolean('remember')]);

        return redirect()->route('login.verify');
    }

    public function edit(Request $request): Response|RedirectResponse
    {
        $to = $request->session()->get('otp_to');

        if (! $to) {
            return redirect()->route('login');
        }

        $email = $request->session()->get('otp_method') === 'email';

        return Inertia::render('Auth/Verify', [
            'destination' => $email ? self::maskEmail($to) : PhoneNumber::mask($to),
            'method' => $email ? 'email' : 'whatsapp',
            'channel' => $request->session()->get('otp_channel', 'whatsapp'),
            'smsAvailable' => ! $email && (bool) config('lotlink.otp.sms_fallback'),
            'resendAfter' => 30,
        ]);
    }

    public function update(Request $request, VerifyOtp $verifyOtp): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:'.config('lotlink.otp.length')]]);

        $to = $request->session()->get('otp_to');

        if (! $to) {
            return redirect()->route('login');
        }

        try {
            $user = $request->session()->get('otp_method') === 'email'
                ? $verifyOtp->forEmail($to, $request->string('code'))
                : $verifyOtp->run($to, $request->string('code'));
        } catch (OtpException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        // "Keep me signed in for a week" ticked on the sign-in page: a remember cookie (auth.guards.web.remember).
        Auth::login($user, remember: (bool) $request->session()->get('otp_remember', false));
        $request->session()->forget(['otp_to', 'otp_method', 'otp_channel', 'otp_remember']);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function resend(Request $request, SendOtp $sendOtp): RedirectResponse
    {
        $to = $request->session()->get('otp_to');

        if (! $to) {
            return redirect()->route('login');
        }

        try {
            if ($request->session()->get('otp_method') === 'email') {
                $sendOtp->toEmail($to);

                return back()->with('success', 'We emailed you a new code.');
            }

            $channel = $request->input('channel') === 'sms' ? 'sms' : null;
            ['channel' => $used] = $sendOtp->run($to, channel: $channel);
        } catch (OtpException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        $request->session()->put('otp_channel', $used);

        return back()->with('success', $used === 'sms' ? 'We sent a new code by SMS.' : 'We sent you a new code on WhatsApp.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        // This device stops getting the account's pushes (the page also unsubscribes the browser).
        if ($endpoint = $request->session()->get('push_endpoint')) {
            PushSubscription::query()->where('endpoint_hash', PushSubscription::hash((string) $endpoint))->delete();
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    /** ada.obi@gmail.com → a•••i@gmail.com */
    public static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $shown = mb_strlen($name) <= 2 ? mb_substr($name, 0, 1).'•' : mb_substr($name, 0, 1).'•••'.mb_substr($name, -1);

        return "{$shown}@{$domain}";
    }
}
