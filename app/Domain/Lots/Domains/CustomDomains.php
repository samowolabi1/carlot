<?php

namespace App\Domain\Lots\Domains;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Custom domains for mini-sites (TDD M6, Enterprise): the seller points its domain at CarYard with a
 * CNAME and proves ownership with a TXT record at _caryard.{domain}. Verified domains serve the
 * lot's page; the TLS "ask" endpoint only allows these.
 */
class CustomDomains
{
    public function __construct(private readonly DnsLookup $dns) {}

    public function set(Lot $lot, User $user, string $domain): Lot
    {
        $domain = self::normalise($domain);

        if (! preg_match('/^(?=.{4,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $domain)) {
            throw ValidationException::withMessages(['domain' => 'Enter a domain like primemotors.ng or cars.primemotors.ng.']);
        }

        if ($domain === self::appHost() || str_ends_with($domain, '.'.self::appHost())) {
            throw ValidationException::withMessages(['domain' => 'Use a domain you own, not a CarYard address.']);
        }

        if (Lot::where('custom_domain', $domain)->whereKeyNot($lot->id)->exists()) {
            throw ValidationException::withMessages(['domain' => 'Another seller already uses that domain.']);
        }

        $lot->forceFill(['custom_domain' => $domain, 'domain_token' => Str::lower(Str::random(32)), 'domain_verified_at' => null])->save();
        AuditLog::record('lot.domain_set', $lot, ['domain' => $domain], $user, $lot->id);
        self::forget($domain);

        return $lot;
    }

    public function verify(Lot $lot): bool
    {
        if ($lot->custom_domain === null || $lot->domain_token === null) {
            return false;
        }

        $expected = 'caryard-verify='.$lot->domain_token;
        $found = collect($this->dns->txt('_caryard.'.$lot->custom_domain))->contains(fn (string $txt) => trim($txt, ' "') === $expected);

        if ($found && $lot->domain_verified_at === null) {
            $lot->forceFill(['domain_verified_at' => now()])->save();
            self::forget($lot->custom_domain);
        }

        return $found;
    }

    public function remove(Lot $lot, User $user): void
    {
        $old = $lot->custom_domain;
        $lot->forceFill(['custom_domain' => null, 'domain_token' => null, 'domain_verified_at' => null])->save();
        AuditLog::record('lot.domain_removed', $lot, ['domain' => $old], $user, $lot->id);
        if ($old) {
            self::forget($old);
        }
    }

    /** The live lot a verified custom domain belongs to, if any (cached for 10 minutes). */
    public static function lotFor(string $host): ?Lot
    {
        $host = self::normalise($host);

        if ($host === self::appHost()) {
            return null;
        }

        $id = Cache::remember("domain:{$host}", now()->addMinutes(10), fn () => Lot::where('custom_domain', $host)
            ->whereNotNull('domain_verified_at')->where('status', LotStatus::Active)->value('id') ?? 0);

        return $id ? Lot::find($id) : null;
    }

    public static function normalise(string $domain): string
    {
        $domain = Str::lower(trim($domain));
        $domain = (string) preg_replace('#^https?://#', '', $domain);
        $domain = explode('/', $domain)[0];
        $domain = explode(':', $domain)[0];

        return rtrim($domain, '.');
    }

    public static function appHost(): string
    {
        return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    private static function forget(string $domain): void
    {
        Cache::forget("domain:{$domain}");
    }
}
