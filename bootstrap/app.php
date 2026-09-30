<?php

use App\Http\Middleware\EnsureProfileComplete;
use App\Http\Middleware\EnsureTermsAccepted;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCurrentLender;
use App\Http\Middleware\SetCurrentLot;
use App\Http\Middleware\TouchLastSeen;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Caddy, Cloudflare or a load balancer, trust its X-Forwarded-* headers so HTTPS
        // (HSTS, secure cookies, signed URLs) is detected. TRUSTED_PROXIES: "*" or a list of IPs.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', (string) $proxies)));
        }

        $middleware->web(append: [
            SecurityHeaders::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            TouchLastSeen::class,
        ]);
        $middleware->api(append: [TouchLastSeen::class]);

        // Payment providers can't send a CSRF token; the webhooks check their signature or secret hash instead.
        $middleware->validateCsrfTokens(except: ['webhooks/paystack', 'webhooks/flutterwave', 'webhooks/finance/*']);

        $middleware->alias([
            'lot.member' => SetCurrentLot::class,
            'lender.member' => SetCurrentLender::class,
            'profile.complete' => EnsureProfileComplete::class,
            'terms.accepted' => EnsureTermsAccepted::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The mobile API always answers in JSON (401/403/404/422), never with a redirect or HTML page.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
