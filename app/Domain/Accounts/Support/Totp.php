<?php

namespace App\Domain\Accounts\Support;

use Illuminate\Support\Str;

/**
 * Time-based one-time passwords (RFC 6238, the codes authenticator apps show) for admin 2FA
 * (TDD M1). 6 digits, 30-second steps, one step of clock drift allowed either side.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function code(string $secret, ?int $time = null): string
    {
        $counter = intdiv($time ?? time(), 30);
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), self::decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code, ?int $time = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $time ??= time();
        foreach ([-1, 0, 1] as $step) {
            if (hash_equals(self::code($secret, $time + $step * 30), $code)) {
                return true;
            }
        }

        return false;
    }

    /** otpauth:// link for the QR code authenticator apps scan. */
    public static function uri(string $secret, string $account, string $issuer = 'CarYard Admin'): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query(['secret' => $secret, 'issuer' => $issuer, 'digits' => 6, 'period' => 30]);
    }

    /** @return list<string> one-time recovery codes, e.g. "k3f9-2xq7" */
    public static function recoveryCodes(int $count = 8): array
    {
        return array_map(fn () => Str::lower(Str::random(4).'-'.Str::random(4)), range(1, $count));
    }

    public static function base32(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        return implode('', array_map(fn (string $chunk) => self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0'))], str_split($bits, 5)));
    }

    private static function decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos !== false) {
                $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
            }
        }

        return implode('', array_map(fn (string $byte) => chr((int) bindec($byte)), array_filter(str_split($bits, 8), fn (string $b) => strlen($b) === 8)));
    }
}
