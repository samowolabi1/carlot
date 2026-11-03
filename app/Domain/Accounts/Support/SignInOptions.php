<?php

namespace App\Domain\Accounts\Support;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Platform\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Whether sign-in codes can go by WhatsApp. Each one is a paid Meta "authentication" message, so it starts off
 * (`lotlink.otp.whatsapp`, OTP_WHATSAPP) and an admin turns it on in /admin → Settings → Sign-in once the WhatsApp
 * account is set up. While it's off, codes go by email only (free): SendOtp::run refuses, and the sign-in pages and
 * the API offer only email (and passwords, Google). Other WhatsApp messages (bookings, receipts) aren't affected.
 */
final class SignInOptions
{
    public const KEY = 'sign_in';

    private const CACHE = 'lotlink.settings.sign_in';

    public static function whatsappCodes(): bool
    {
        try {
            $saved = Cache::rememberForever(self::CACHE, function (): array {
                $setting = PlatformSetting::query()->where('key', self::KEY)->first();

                return $setting === null ? [] : $setting->value;
            });
        } catch (Throwable) {
            $saved = [];
        }

        return (bool) ($saved['whatsapp'] ?? config('lotlink.otp.whatsapp', false));
    }

    public static function setWhatsappCodes(bool $on, ?User $by = null): void
    {
        $before = self::whatsappCodes();
        PlatformSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => ['whatsapp' => $on], 'updated_by' => $by?->id]);
        Cache::forget(self::CACHE);

        if ($before !== $on) {
            AuditLog::record('admin.whatsapp_codes_changed', null, ['before' => $before, 'after' => $on], $by);
        }
    }
}
