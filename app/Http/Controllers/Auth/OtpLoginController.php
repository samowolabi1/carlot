<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Accounts\Actions\SendOtp;
use App\Domain\Accounts\Actions\VerifyOtp;
use App\Domain\Accounts\Exceptions\OtpException;
use App\Domain\Support\PhoneNumber;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class OtpLoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request, SendOtp $sendOtp): RedirectResponse
    {
        $request->validate(['phone' => ['required', 'string', 'max:32']]);

        try {
            $phone = PhoneNumber::normalize($request->string('phone'));
            ['channel' => $channel] = $sendOtp->run($phone);
        } catch (InvalidArgumentException|OtpException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }

        $request->session()->put(['otp_phone' => $phone, 'otp_channel' => $channel]);

        return redirect()->route('login.verify');
    }

    public function edit(Request $request): Response|RedirectResponse
    {
        $phone = $request->session()->get('otp_phone');

        if (! $phone) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/Verify', [
            'maskedPhone' => PhoneNumber::mask($phone),
            'channel' => $request->session()->get('otp_channel', 'sms'),
            'resendAfter' => 30,
        ]);
    }

    public function update(Request $request, VerifyOtp $verifyOtp): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:'.config('lotlink.otp.length')]]);

        $phone = $request->session()->get('otp_phone');

        if (! $phone) {
            return redirect()->route('login');
        }

        try {
            $user = $verifyOtp->run($phone, $request->string('code'));
        } catch (OtpException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        Auth::login($user, remember: true);
        $request->session()->forget('otp_phone');
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function resend(Request $request, SendOtp $sendOtp): RedirectResponse
    {
        $phone = $request->session()->get('otp_phone');

        if (! $phone) {
            return redirect()->route('login');
        }

        $channel = $request->input('channel') === 'sms' ? 'sms' : null;

        try {
            ['channel' => $used] = $sendOtp->run($phone, channel: $channel);
        } catch (OtpException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        $request->session()->put('otp_channel', $used);

        return back()->with('success', $used === 'sms' ? 'We sent a new code by SMS.' : 'We sent you a new code on WhatsApp.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
