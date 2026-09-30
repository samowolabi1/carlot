<?php

namespace App\Domain\Trust\Registry;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Dojah's CAC lookup: GET {base}/api/v1/kyc/cac/basic?rc_number=…&company_type=… with the app's
 * `AppId` and secret key (`Authorization`, sent as is). Sandbox: https://sandbox.dojah.io.
 */
final class DojahCompanyRegistry implements CompanyRegistry
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $appId,
        private readonly string $secretKey,
    ) {}

    public function enabled(): bool
    {
        return $this->appId !== '' && $this->secretKey !== '';
    }

    public function lookup(string $number): ?CompanyRecord
    {
        [$digits, $type] = self::split($number);

        try {
            $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->withHeaders(['AppId' => $this->appId, 'Authorization' => $this->secretKey])
                ->acceptJson()->timeout(15)->retry(2, 500, throw: false)
                ->get('/api/v1/kyc/cac/basic', ['rc_number' => $digits, 'company_type' => $type]);
        } catch (ConnectionException $e) {
            throw new RegistryUnavailable('The CAC lookup could not be reached.', previous: $e);
        }

        // Dojah answers 404 (or 400 with an error) when the registry has no such number.
        if ($response->status() === 404 || ($response->status() === 400 && ! $response->json('entity'))) {
            return null;
        }
        if (! $response->successful()) {
            throw new RegistryUnavailable("The CAC lookup answered {$response->status()}.");
        }

        $entity = (array) ($response->json('entity') ?? []);
        $name = trim((string) ($entity['company_name'] ?? $entity['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $address = $entity['address'] ?? collect([$entity['city'] ?? null, $entity['lga'] ?? null, $entity['state'] ?? null])->filter()->implode(', ');

        return new CompanyRecord(
            name: $name,
            status: isset($entity['status']) ? ucfirst(strtolower((string) $entity['status'])) : null,
            registeredOn: self::date($entity['date_of_registration'] ?? $entity['registration_date'] ?? null),
            address: $address !== '' ? mb_substr((string) $address, 0, 255) : null,
        );
    }

    /** "1234567" → [1234567, COMPANY]; "BN123456" → [123456, BUSINESS_NAME]; "IT…" → INCORPORATED_TRUSTEES. @return array{string, string} */
    public static function split(string $number): array
    {
        $prefix = strtoupper(substr($number, 0, 2));

        return match ($prefix) {
            'BN' => [substr($number, 2), 'BUSINESS_NAME'],
            'IT' => [substr($number, 2), 'INCORPORATED_TRUSTEES'],
            default => [$number, 'COMPANY'],
        };
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
