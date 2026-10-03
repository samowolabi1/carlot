<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Finance\Actions\UpdateFinanceApplication;
use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Enums\LenderIntegration;
use App\Domain\Finance\Lenders\HttpConnection;
use App\Domain\Finance\Models\Lender;
use App\Domain\Support\Fields;
use App\Http\Controllers\Controller;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Status updates from a lender's own system (/webhooks/finance/{lender}), signed with HMAC-SHA256 of the raw body
 * using that lender's webhook secret (header X-CarYard-Signature; the older X-LotLink-Signature is still accepted). Unsigned or badly signed calls are refused, and
 * a lender can only touch its own applications.
 */
class FinanceWebhookController extends Controller
{
    public function __invoke(Request $request, Lender $lender, UpdateFinanceApplication $update): JsonResponse
    {
        $secret = (string) $lender->webhook_secret;
        $signature = (string) ($request->header('X-CarYard-Signature') ?? $request->header('X-LotLink-Signature'));

        abort_if($lender->integration !== LenderIntegration::Api || $secret === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature), 401);

        $data = $this->validated($request, [
            'reference' => ['required', 'string', 'max:100'],
            'status' => ['required', Rule::in(HttpConnection::STATUSES)],
            'message' => Fields::text(1000),
            'next_steps' => Fields::text(1000),
            'approved_amount' => ['nullable', 'integer', 'min:0', 'max:'.Fields::MONEY_MAX],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tenor_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'disbursed_amount' => ['nullable', 'integer', 'min:0', 'max:'.Fields::MONEY_MAX],
            'disbursed_reference' => ['nullable', 'string', 'max:64'],
        ]);

        // Our reference (the application's ULID, sent when it was created) or the lender's own.
        $application = $lender->applications()->where(fn ($q) => $q->where('ulid', $data['reference'])->orWhere('external_ref', $data['reference']))->firstOrFail();

        try {
            $update->run($application, FinanceStatus::from($data['status']), [
                'message' => $data['message'] ?? null,
                'next_steps' => $data['next_steps'] ?? null,
                'approved_amount' => isset($data['approved_amount']) ? $data['approved_amount'] * 100 : null,
                'offer_rate_bp' => isset($data['rate']) ? (int) round($data['rate'] * 100) : null,
                'offer_tenor_months' => $data['tenor_months'] ?? null,
                'disbursed_amount' => isset($data['disbursed_amount']) ? $data['disbursed_amount'] * 100 : null,
                'disbursed_reference' => $data['disbursed_reference'] ?? null,
            ]);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
        }

        return response()->json(['ok' => true, 'status' => $application->refresh()->status->value]);
    }

    /**
     * Lenders' systems get JSON errors whatever they send as Accept.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            throw new HttpResponseException(response()->json(['ok' => false, 'errors' => $validator->errors()], 422));
        }

        return $validator->validated();
    }
}
