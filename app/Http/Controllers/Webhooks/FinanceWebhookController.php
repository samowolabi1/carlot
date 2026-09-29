<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Finance\Actions\UpdateFinanceApplication;
use App\Domain\Finance\Models\FinanceApplication;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Status updates from the finance partner, signed with HMAC-SHA256 of the raw body using the
 * shared secret (header X-LotLink-Signature). Unsigned or badly signed calls are refused.
 */
class FinanceWebhookController extends Controller
{
    public function __invoke(Request $request, UpdateFinanceApplication $update): JsonResponse
    {
        $secret = (string) config('lotlink.finance_partner.webhook_secret');
        $signature = (string) $request->header('X-LotLink-Signature');

        abort_if($secret === '' || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature), 401);

        $data = $request->validate([
            'reference' => ['required', 'string', 'max:100'],
            'status' => ['required', 'in:received,pre_approved,declined'],
            'message' => ['nullable', 'string', 'max:255'],
            'approved_amount' => ['nullable', 'integer', 'min:0'],
        ]);

        $application = FinanceApplication::where('partner', config('lotlink.finance_partner.code'))->where('external_ref', $data['reference'])->firstOrFail();
        $update->run($application, $data['status'], $data['message'] ?? null, isset($data['approved_amount']) ? $data['approved_amount'] * 100 : null);

        return response()->json(['ok' => true]);
    }
}
