<?php

namespace App\Domain\Finance\Partners;

use App\Domain\Finance\Models\FinanceApplication;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A partner with a JSON API (FINANCE_PARTNER_DRIVER=http): POST {url}/applications with a bearer
 * key; it answers {reference, status, message?, approved_amount?} and may update the status later
 * through /webhooks/finance (signed).
 */
class HttpFinancePartner implements FinancePartner
{
    public function __construct(private readonly string $code, private readonly string $name, private readonly string $url, private readonly string $key) {}

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function submit(FinanceApplication $application): array
    {
        $response = Http::withToken($this->key)->acceptJson()->timeout(20)->post(rtrim($this->url, '/').'/applications', [
            'reference' => $application->ulid,
            'amount' => intdiv($application->amount, 100),
            'deposit' => intdiv($application->deposit, 100),
            'currency' => $application->currency,
            'tenor_months' => $application->tenor_months,
            'vehicle' => $application->vehicle ? ['title' => $application->vehicle->title(), 'year' => $application->vehicle->year, 'price' => intdiv((int) $application->vehicle->price, 100)] : null,
            'applicant' => $application->applicant,
            'consented_at' => $application->consented_at->toIso8601String(),
            'callback_url' => route('webhooks.finance'),
        ]);

        if (! $response->successful() || ! $response->json('reference')) {
            throw new RuntimeException('Finance partner returned '.$response->status());
        }

        return [
            'reference' => (string) $response->json('reference'),
            'status' => in_array($response->json('status'), ['received', 'pre_approved', 'declined'], true) ? (string) $response->json('status') : 'received',
            'message' => $response->json('message'),
            'approved_amount' => is_numeric($response->json('approved_amount')) ? (int) $response->json('approved_amount') * 100 : null,
        ];
    }
}
