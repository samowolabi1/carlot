<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Finance\Enums\LenderIntegration;
use App\Domain\Finance\Models\Lender;
use App\Domain\Support\PhoneNumber;
use App\Domain\Support\Regions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Saves a lender's profile, loan product and connection (by the lender's admins or a LotLink admin). Amounts come in
 * whole naira and the rate as a percentage; a lender that switches to its own API gets a webhook secret.
 */
class SaveLender
{
    /**
     * @param  array<string, mixed>  $data  any of: name, licence_type, licence_number, contact_name, contact_email, contact_phone, website, about,
     *                                      rate, min_amount, max_amount, min_deposit_percent, tenors, states, integration, api_url, api_key
     */
    public function run(Lender $lender, array $data, ?User $by = null): Lender
    {
        $lender->fill(self::attributes($data, $lender));

        if ($lender->min_amount > $lender->max_amount) {
            throw ValidationException::withMessages(['max_amount' => 'The largest loan must be at least the smallest.']);
        }
        if ($lender->integration === LenderIntegration::Api) {
            if (blank($lender->api_url) || blank($lender->api_key)) {
                throw ValidationException::withMessages(['api_url' => 'Add your API address and key, or keep working in the portal.']);
            }
            $lender->webhook_secret ??= Str::random(48);
        }

        $changed = array_keys($lender->getDirty());
        $lender->save();

        if ($changed !== [] && $by !== null) {
            // Secrets are never written to the log, only that they changed.
            AuditLog::record('lender.saved', $lender, ['fields' => $changed], $by);
        }

        return $lender;
    }

    public function rotateSecret(Lender $lender, User $by): string
    {
        $lender->update(['webhook_secret' => $secret = Str::random(48)]);
        AuditLog::record('lender.secret_rotated', $lender, [], $by);

        return $secret;
    }

    /**
     * The model's attributes from form input (naira, percent, lists).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function attributes(array $data, ?Lender $lender = null): array
    {
        $out = [];
        foreach (['name', 'licence_number', 'contact_name', 'website', 'about', 'next_steps', 'api_url'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = filled($data[$key]) ? trim((string) $data[$key]) : null;
            }
        }
        foreach (['licence_type', 'integration'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }
        if (array_key_exists('contact_email', $data)) {
            $out['contact_email'] = Str::lower(trim((string) $data['contact_email']));
        }
        if (array_key_exists('contact_phone', $data)) {
            try {
                $out['contact_phone'] = PhoneNumber::normalize((string) $data['contact_phone']);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['contact_phone' => $e->getMessage()]);
            }
        }
        if (array_key_exists('rate', $data)) {
            $out['rate_bp'] = (int) round(((float) $data['rate']) * 100);
        }
        foreach (['min_amount', 'max_amount'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = (int) $data[$key] * 100;
            }
        }
        if (array_key_exists('min_deposit_percent', $data)) {
            $out['min_deposit_percent'] = (int) $data['min_deposit_percent'];
        }
        if (array_key_exists('tenors', $data)) {
            $tenors = array_values(array_unique(array_map('intval', (array) $data['tenors'])));
            sort($tenors);
            $out['tenors'] = array_values(array_intersect($tenors, Lender::TENORS));
            if ($out['tenors'] === []) {
                throw ValidationException::withMessages(['tenors' => 'Pick at least one loan length.']);
            }
        }
        if (array_key_exists('states', $data)) {
            $states = collect((array) ($data['states'] ?? []))->map(fn ($s) => Regions::normalize((string) $s))->filter()->unique()->sort()->values()->all();
            $out['states'] = $states === [] ? null : $states;
        }
        // A blank key field keeps the saved key (it is never sent back to the browser).
        if (filled($data['api_key'] ?? null)) {
            $out['api_key'] = trim((string) $data['api_key']);
        }
        if ($lender === null && isset($out['name'])) {
            $out['slug'] = self::slug((string) $out['name']);
        }

        return $out;
    }

    public static function slug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 60, '') ?: 'lender';
        $slug = $base;
        for ($i = 2; Lender::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
