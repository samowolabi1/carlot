<?php

namespace App\Providers;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Policies\VehiclePolicy;
use App\Domain\Inventory\Support\NhtsaVinDecoder;
use App\Domain\Inventory\Support\VinDecoder;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Policies\LotPolicy;
use App\Domain\Lots\Support\CurrentLot;
use App\Domain\Marketplace\Search\DatabaseVehicleSearch;
use App\Domain\Marketplace\Search\MeilisearchVehicleSearch;
use App\Domain\Marketplace\Search\VehicleSearch;
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

        $this->app->bind(VehicleSearch::class, fn () => config('scout.driver') === 'meilisearch'
            ? new MeilisearchVehicleSearch
            : new DatabaseVehicleSearch);

        $this->app->bind(VinDecoder::class, fn () => new NhtsaVinDecoder(config('services.nhtsa.base_url')));

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
        Gate::policy(Vehicle::class, VehiclePolicy::class);

        RateLimiter::for('otp', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
