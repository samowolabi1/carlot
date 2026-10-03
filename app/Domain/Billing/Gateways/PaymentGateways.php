<?php

namespace App\Domain\Billing\Gateways;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Platform\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * The payment providers CarYard can take its own payments through (Paystack, Flutterwave) and
 * which one new payments use: an admin picks it in /admin → Settings → Payments. A payment or
 * subscription always goes back to the provider that took it (`for($payment->provider)`), so
 * switching never strands refunds, cancellations or renewals.
 */
final class PaymentGateways
{
    public const PROVIDERS = ['paystack' => 'Paystack', 'flutterwave' => 'Flutterwave'];

    public const KEY = 'payments';

    private const CACHE = 'lotlink.settings.payments';

    /** The gateway for a provider name (as stored on payments and subscriptions). */
    public function for(?string $provider): PaymentGateway
    {
        $provider = $provider ?: self::activeProvider();
        if ($provider !== 'sandbox' && ! array_key_exists($provider, self::PROVIDERS)) {
            throw new InvalidArgumentException("Unknown payment provider {$provider}.");
        }

        return app("payments.{$provider}");
    }

    /** The gateway new payments go through. */
    public function active(): PaymentGateway
    {
        return $this->for(self::activeProvider());
    }

    public static function activeProvider(): string
    {
        try {
            $saved = Cache::rememberForever(self::CACHE, function (): array {
                $setting = PlatformSetting::query()->where('key', self::KEY)->first();

                return $setting === null ? [] : $setting->value;
            });
        } catch (Throwable) {
            $saved = [];
        }
        $provider = (string) ($saved['provider'] ?? config('lotlink.billing.provider', 'paystack'));

        return array_key_exists($provider, self::PROVIDERS) ? $provider : 'paystack';
    }

    public static function choose(string $provider, ?User $by = null): void
    {
        if (! array_key_exists($provider, self::PROVIDERS)) {
            throw new InvalidArgumentException("Unknown payment provider {$provider}.");
        }
        $before = self::activeProvider();
        PlatformSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => ['provider' => $provider], 'updated_by' => $by?->id]);
        Cache::forget(self::CACHE);

        AuditLog::record('admin.payment_provider_changed', null, ['before' => $before, 'after' => $provider], $by);
    }

    /** Real providers (PAYMENT_DRIVER=live), or the test checkout. */
    public static function live(): bool
    {
        return in_array(config('lotlink.billing.driver'), ['live', 'paystack', 'flutterwave'], true);
    }

    /** Whether a provider's keys are set (the sandbox needs none). */
    public static function configured(string $provider): bool
    {
        if (! self::live()) {
            return true;
        }

        return match ($provider) {
            'paystack' => filled(config('services.paystack.secret_key')),
            'flutterwave' => filled(config('services.flutterwave.secret_key')) && filled(config('services.flutterwave.secret_hash')),
            default => false,
        };
    }

    /** Builds a provider's gateway (bound in the container as `payments.{provider}`). */
    public static function make(string $provider): PaymentGateway
    {
        if (! self::live() || $provider === 'sandbox') {
            // The sandbox never takes real money, so it is refused in production.
            abort_if(app()->isProduction(), 500, 'PAYMENT_DRIVER must be live in production.');

            return new SandboxGateway;
        }

        return match ($provider) {
            'flutterwave' => new FlutterwaveGateway((string) config('services.flutterwave.secret_key'), (string) config('services.flutterwave.secret_hash'), (string) config('services.flutterwave.base_url')),
            default => new PaystackGateway((string) config('services.paystack.secret_key'), (string) config('services.paystack.base_url')),
        };
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE);
    }
}
