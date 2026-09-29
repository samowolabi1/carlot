<?php

namespace App\Http\Middleware;

use App\Domain\Lots\Domains\CustomDomains;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers (TDD Security): HSTS on HTTPS, a Content Security Policy with per-request
 * nonces (self + Google Maps + Paystack + fonts + the media CDN), and no framing except the
 * mini-site, which lots may embed in their own websites. The Filament admin keeps its own
 * inline scripts, so it gets the other headers but not the CSP.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $csp = self::cspEnabled() && ! $request->is('admin', 'admin/*', 'livewire/*');

        if ($csp) {
            Vite::useCspNonce();
        }

        $response = $next($request);

        $embeddable = $request->is('l/*') || ($request->is('/') && CustomDomains::lotFor($request->getHost()) !== null);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(self), camera=(self), microphone=(), payment=()');

        if (! $embeddable) {
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($csp && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', self::policy(Vite::cspNonce(), $embeddable));
        }

        return $response;
    }

    public static function cspEnabled(): bool
    {
        return (bool) config('lotlink.csp');
    }

    public static function policy(?string $nonce, bool $embeddable = false): string
    {
        $media = array_filter([self::origin(config('filesystems.disks.r2_media.url')), self::origin(config('filesystems.disks.s3.url'))]);
        $uploads = config('filesystems.disks.r2.endpoint') ? [self::origin(config('filesystems.disks.r2.endpoint'))] : [];
        $reverb = config('broadcasting.connections.reverb.options.host')
            ? ['wss://'.config('broadcasting.connections.reverb.options.host'), 'ws://'.config('broadcasting.connections.reverb.options.host')]
            : [];

        $rules = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", $nonce ? "'nonce-{$nonce}'" : null, 'https://maps.googleapis.com', 'https://maps.gstatic.com'],
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
            'font-src' => ["'self'", 'data:', 'https://fonts.gstatic.com'],
            'img-src' => ["'self'", 'data:', 'blob:', 'https://maps.gstatic.com', 'https://*.googleapis.com', 'https://*.ggpht.com', ...$media],
            'connect-src' => ["'self'", 'https://maps.googleapis.com', ...$uploads, ...$reverb],
            'frame-src' => ["'self'", 'https://checkout.paystack.com'],
            'frame-ancestors' => [$embeddable ? '*' : "'none'"],
            'form-action' => ["'self'"],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
        ];

        return collect($rules)->map(fn (array $sources, string $name) => $name.' '.implode(' ', array_unique(array_filter($sources))))->implode('; ');
    }

    private static function origin(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);

        return isset($parts['scheme'], $parts['host']) ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') : null;
    }
}
