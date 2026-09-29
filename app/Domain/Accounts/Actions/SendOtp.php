<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\OtpPurpose;
use App\Domain\Accounts\Exceptions\OtpException;
use App\Domain\Accounts\Mail\LoginCode;
use App\Domain\Accounts\Models\OtpCode;
use App\Domain\Messaging\Message;
use App\Domain\Messaging\Messenger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * One-time sign-in codes, to a WhatsApp number or an email address (people choose). WhatsApp
 * doesn't fall back to paid SMS unless OTP_SMS_FALLBACK is on; email costs nothing.
 */
class SendOtp
{
    public function __construct(private readonly Messenger $messenger) {}

    /**
     * @param  string  $phone  E.164 number
     * @param  'whatsapp'|'sms'|null  $channel  null uses the configured default
     * @return array{otp: OtpCode, channel: 'whatsapp'|'sms'}
     */
    public function run(string $phone, OtpPurpose $purpose = OtpPurpose::Login, ?string $channel = null): array
    {
        $config = config('lotlink.otp');
        $channel ??= $config['channel'];
        $fallback = (bool) ($config['sms_fallback'] ?? false);

        if ($channel === 'sms' && ! $fallback && $config['channel'] !== 'sms') {
            throw new OtpException('Codes are only sent on WhatsApp or by email. Use email if this number isn\'t on WhatsApp.');
        }

        [$otp, $code] = $this->issue('phone', $phone, $purpose);

        try {
            $used = $this->messenger->send($phone, new Message(
                template: 'login_code',
                params: [$code],
                text: "Your LotLink code is {$code}. It expires in {$config['ttl_minutes']} minutes. Don't share it with anyone.",
                authentication: true,
            ), preferWhatsApp: $channel === 'whatsapp', smsFallback: $fallback);
        } catch (Throwable $e) {
            Log::warning("Sign-in code to {$phone} failed: {$e->getMessage()}");
            $otp->update(['consumed_at' => now()]);

            throw new OtpException('We couldn\'t reach that number on WhatsApp. Check the number, or sign in with your email instead.');
        }

        return ['otp' => $otp, 'channel' => $used === 'sms' ? 'sms' : 'whatsapp'];
    }

    /** A code by email (free to send). */
    public function toEmail(string $email, OtpPurpose $purpose = OtpPurpose::Login): OtpCode
    {
        [$otp, $code] = $this->issue('email', $email, $purpose);
        Mail::to($email)->send(new LoginCode($code, (int) config('lotlink.otp.ttl_minutes')));

        return $otp;
    }

    /**
     * @param  'phone'|'email'  $field
     * @return array{0: OtpCode, 1: string}
     */
    private function issue(string $field, string $value, OtpPurpose $purpose): array
    {
        $config = config('lotlink.otp');

        $recentSends = OtpCode::query()
            ->where($field, $value)
            ->where('purpose', $purpose)
            ->where('created_at', '>', now()->subMinutes($config['send_window_minutes']))
            ->count();

        if ($recentSends >= $config['max_sends']) {
            throw OtpException::tooManySends($config['send_window_minutes']);
        }

        // Only the newest code is valid.
        OtpCode::query()->where($field, $value)->where('purpose', $purpose)->whereNull('consumed_at')->update(['consumed_at' => now()]);

        $code = str_pad((string) random_int(0, 10 ** $config['length'] - 1), $config['length'], '0', STR_PAD_LEFT);

        $otp = OtpCode::create([
            $field => $value,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($config['ttl_minutes']),
        ]);

        return [$otp, $code];
    }
}
