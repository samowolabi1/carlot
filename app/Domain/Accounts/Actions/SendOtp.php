<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\OtpPurpose;
use App\Domain\Accounts\Exceptions\OtpException;
use App\Domain\Accounts\Models\OtpCode;
use App\Domain\Messaging\SmsGateway;
use Illuminate\Support\Facades\Hash;

class SendOtp
{
    public function __construct(private readonly SmsGateway $sms) {}

    /** @param string $phone E.164 number */
    public function run(string $phone, OtpPurpose $purpose = OtpPurpose::Login): OtpCode
    {
        $config = config('lotlink.otp');

        $recentSends = OtpCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->where('created_at', '>', now()->subMinutes($config['send_window_minutes']))
            ->count();

        if ($recentSends >= $config['max_sends']) {
            throw OtpException::tooManySends($config['send_window_minutes']);
        }

        // Only the newest code is valid.
        OtpCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = str_pad((string) random_int(0, 10 ** $config['length'] - 1), $config['length'], '0', STR_PAD_LEFT);

        $otp = OtpCode::create([
            'phone' => $phone,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($config['ttl_minutes']),
        ]);

        $this->sms->send($phone, "Your LotLink code is {$code}. It expires in {$config['ttl_minutes']} minutes. Don't share it with anyone.");

        return $otp;
    }
}
