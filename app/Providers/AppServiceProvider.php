<?php

namespace App\Providers;

use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Policies\AppointmentPolicy;
use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Billing\Gateways\PaystackGateway;
use App\Domain\Billing\Gateways\SandboxGateway;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Policies\VehiclePolicy;
use App\Domain\Inventory\Support\NhtsaVinDecoder;
use App\Domain\Inventory\Support\VinDecoder;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Policies\SalesOrderPolicy;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Policies\LotPolicy;
use App\Domain\Lots\Support\CurrentLot;
use App\Domain\Marketplace\Search\DatabaseVehicleSearch;
use App\Domain\Marketplace\Search\MeilisearchVehicleSearch;
use App\Domain\Marketplace\Search\VehicleSearch;
use App\Domain\Messaging\Channels\PhoneChannel;
use App\Domain\Messaging\LogSmsGateway;
use App\Domain\Messaging\LogWhatsAppGateway;
use App\Domain\Messaging\MetaWhatsAppGateway;
use App\Domain\Messaging\SmsGateway;
use App\Domain\Messaging\TermiiSmsGateway;
use App\Domain\Messaging\WhatsAppGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentLot::class);

        $this->app->bind(WhatsAppGateway::class, fn () => match (config('lotlink.whatsapp_driver')) {
            'meta' => new MetaWhatsAppGateway(
                (string) config('services.whatsapp.token'),
                (string) config('services.whatsapp.phone_number_id'),
                (string) config('services.whatsapp.api_version'),
                (string) config('services.whatsapp.language'),
            ),
            default => new LogWhatsAppGateway,
        });

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

        $this->app->bind(PaymentGateway::class, function () {
            if (config('lotlink.billing.driver') === 'paystack') {
                return new PaystackGateway((string) config('services.paystack.secret_key'), (string) config('services.paystack.base_url'));
            }

            // The sandbox never takes real money, so it is refused in production.
            abort_if(app()->isProduction(), 500, 'PAYMENT_DRIVER must be paystack in production.');

            return new SandboxGateway;
        });
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        Notification::extend('phone', fn ($app) => $app->make(PhoneChannel::class));

        Gate::policy(Lot::class, LotPolicy::class);
        Gate::policy(Vehicle::class, VehiclePolicy::class);
        Gate::policy(Appointment::class, AppointmentPolicy::class);
        Gate::policy(SalesOrder::class, SalesOrderPolicy::class);

        RateLimiter::for('otp', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
