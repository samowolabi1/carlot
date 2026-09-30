<?php

namespace App\Providers;

use App\Domain\Admin\AdminCounters;
use App\Domain\Advertising\Models\AdCampaign;
use App\Domain\Advertising\Support\AdvertPricing;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Policies\AppointmentPolicy;
use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Billing\Gateways\PaymentGateways;
use App\Domain\Finance\Partners\FinancePartner;
use App\Domain\Finance\Partners\HttpFinancePartner;
use App\Domain\Finance\Partners\LogFinancePartner;
use App\Domain\Finance\Support\FinanceRates;
use App\Domain\Helpdesk\Models\SupportTicket;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Models\VehicleModel;
use App\Domain\Inventory\Policies\VehiclePolicy;
use App\Domain\Inventory\Support\NhtsaVinDecoder;
use App\Domain\Inventory\Support\VinDecoder;
use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Policies\ConversationPolicy;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Policies\SalesOrderPolicy;
use App\Domain\Lots\Domains\DnsLookup;
use App\Domain\Lots\Domains\SystemDnsLookup;
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
use App\Domain\Push\Channels\PushChannel;
use App\Domain\Push\Gateways\LogPushGateway;
use App\Domain\Push\Gateways\PushGateway;
use App\Domain\Push\Gateways\WebPushGateway;
use App\Domain\Social\Gateways\LogSocialPublisher;
use App\Domain\Social\Gateways\MetaSocialPublisher;
use App\Domain\Social\Gateways\SocialPublisher;
use App\Domain\Trust\Models\FraudSignal;
use App\Domain\Trust\Models\LotVerification;
use App\Domain\Trust\Models\Report;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
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
            ? new MeilisearchVehicleSearch(new DatabaseVehicleSearch)
            : new DatabaseVehicleSearch);

        $this->app->bind(SocialPublisher::class, fn () => config('lotlink.social_driver') === 'meta'
            ? new MetaSocialPublisher((string) config('services.meta.app_id'), (string) config('services.meta.app_secret'), (string) config('services.meta.graph_version'))
            : new LogSocialPublisher);

        $this->app->bind(DnsLookup::class, SystemDnsLookup::class);

        $this->app->bind(PushGateway::class, fn () => filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'))
            ? new WebPushGateway((string) config('services.webpush.subject'), (string) config('services.webpush.public_key'), (string) config('services.webpush.private_key'))
            : new LogPushGateway);

        $this->app->bind(FinancePartner::class, fn () => config('lotlink.finance_partner.driver') === 'http'
            ? new HttpFinancePartner((string) config('lotlink.finance_partner.code'), (string) config('lotlink.finance_partner.name'), (string) config('lotlink.finance_partner.url'), (string) config('lotlink.finance_partner.key'))
            : new LogFinancePartner);

        $this->app->bind(VinDecoder::class, fn () => new NhtsaVinDecoder(config('services.nhtsa.base_url')));

        $this->app->bind(SmsGateway::class, fn () => match (config('lotlink.sms_driver')) {
            'termii' => new TermiiSmsGateway(
                (string) config('services.termii.key'),
                (string) config('services.termii.sender_id'),
                (string) config('services.termii.base_url'),
            ),
            default => new LogSmsGateway,
        });

        // Each provider by name (`payments.paystack`, …); PaymentGateway itself is the one new payments use.
        foreach (['paystack', 'flutterwave', 'sandbox'] as $provider) {
            $this->app->bind("payments.{$provider}", fn () => PaymentGateways::make($provider));
        }
        $this->app->bind(PaymentGateway::class, fn ($app) => $app->make(PaymentGateways::class)->active());
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        Notification::extend('phone', fn ($app) => $app->make(PhoneChannel::class));
        Notification::extend('push', fn ($app) => $app->make(PushChannel::class));

        // Finance rates and advert prices an admin changed in /admin; re-read before each job so long-running workers keep up.
        FinanceRates::apply();
        AdvertPricing::apply();
        Queue::before(function (): void {
            FinanceRates::apply();
            AdvertPricing::apply();
        });

        // The admin menu badges and review queue (AdminCounters) refresh as soon as a queue changes.
        foreach ([Lot::class, LotVerification::class, FraudSignal::class, Report::class, AdCampaign::class, SupportTicket::class, VehicleModel::class] as $model) {
            $model::saved(fn () => AdminCounters::forget());
            $model::deleted(fn () => AdminCounters::forget());
        }

        Gate::policy(Lot::class, LotPolicy::class);
        Gate::policy(Vehicle::class, VehiclePolicy::class);
        Gate::policy(Appointment::class, AppointmentPolicy::class);
        Gate::policy(SalesOrder::class, SalesOrderPolicy::class);
        Gate::policy(Conversation::class, ConversationPolicy::class);

        // Search and SEO pages, per IP (TDD: 120/min). Raise BROWSE_RATE_LIMIT for a load test from one machine.
        RateLimiter::for('browse', fn (Request $request) => Limit::perMinute((int) config('lotlink.browse_rate_limit'))->by($request->ip()));
        RateLimiter::for('otp', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        // Mobile API: per signed-in user, else per address.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute((int) config('lotlink.api_rate_limit', 120))->by($request->user('sanctum')?->getAuthIdentifier() ?? $request->ip()));
    }
}
