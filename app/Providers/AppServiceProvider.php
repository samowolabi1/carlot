<?php

namespace App\Providers;

use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Policies\LotPolicy;
use App\Domain\Lots\Support\CurrentLot;
use App\Domain\Messaging\LogSmsGateway;
use App\Domain\Messaging\SmsGateway;
use App\Domain\Messaging\TermiiSmsGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentLot::class);

        $this->app->bind(SmsGateway::class, fn () => match (config('lotlink.sms_driver')) {
            'termii' => new TermiiSmsGateway(
                (string) config('services.termii.key'),
                (string) config('services.termii.sender_id'),
                (string) config('services.termii.base_url'),
            ),
            default => new LogSmsGateway,
        });
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        Gate::policy(Lot::class, LotPolicy::class);

        RateLimiter::for('otp', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
