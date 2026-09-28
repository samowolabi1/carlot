<?php

use App\Http\Middleware\EnsureProfileComplete;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetCurrentLot;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Paystack can't send a CSRF token; the webhook checks its signature instead.
        $middleware->validateCsrfTokens(except: ['webhooks/paystack']);

        $middleware->alias([
            'lot.member' => SetCurrentLot::class,
            'profile.complete' => EnsureProfileComplete::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
