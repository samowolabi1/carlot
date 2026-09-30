<?php

namespace App\Domain\Finance\Lenders;

use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Models\Lender;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A lender with its own JSON API (integration "api"): POST {api_url}/applications with its bearer key. It answers
 * {reference, status, message?, approved_amount?} and sends later changes to its own signed webhook
 * (/webhooks/finance/{lender}). See docs/api.md.
 */
class HttpConnection implements LenderConnection
{
    public const STATUSES = ['received', 'documents_requested', 'pre_approved', 'approved', 'disbursed', 'declined'];

    public function __construct(private readonly Lender $lender) {}

    public function submit(FinanceApplication $application): ?array
    {
        if (blank($this->lender->api_url) || blank($this->lender->api_key)) {
            throw new RuntimeException("{$this->lender->name} has no API address or key.");
        }

        $response = Http::withToken((string) $this->lender->api_key)->acceptJson()->timeout(20)->post(rtrim((string) $this->lender->api_url, '/').'/applications', [
            'reference' => $application->ulid,
            'amount' => intdiv($application->amount, 100),
            'deposit' => intdiv($application->deposit, 100),
            'currency' => $application->currency,
            'tenor_months' => $application->tenor_months,
            'vehicle' => $application->vehicle ? ['title' => $application->vehicle->title(), 'year' => $application->vehicle->year, 'price' => intdiv((int) $application->vehicle->price, 100)] : null,
            'lot' => $application->lot ? ['name' => $application->lot->name, 'city' => $application->lot->city, 'state' => $application->lot->state] : null,
            'applicant' => $application->applicant,
            'consented_at' => $application->consented_at->toIso8601String(),
            'callback_url' => route('webhooks.finance', $this->lender),
        ]);

        if (! $response->successful() || ! $response->json('reference')) {
            throw new RuntimeException("{$this->lender->name} returned ".$response->status());
        }

        return [
            'reference' => mb_substr((string) $response->json('reference'), 0, 100),
            'status' => in_array($response->json('status'), ['received', 'pre_approved', 'declined'], true) ? (string) $response->json('status') : 'received',
            'message' => $response->json('message'),
            'approved_amount' => is_numeric($response->json('approved_amount')) ? (int) $response->json('approved_amount') * 100 : null,
        ];
    }
}
