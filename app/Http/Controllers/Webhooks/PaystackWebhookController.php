<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Billing\Actions\HandlePaystackEvent;
use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Billing\Models\WebhookEvent;
use App\Http\Controllers\Controller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * POST /webhooks/paystack (TDD M16): checks x-paystack-signature against the raw body,
 * stores the event, and handles each delivery once. Paystack retries anything that
 * doesn't get a 200, so errors after storing are logged and still answered 200.
 */
class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentGateway $gateway, HandlePaystackEvent $handle): Response
    {
        $payload = $request->getContent();

        if (! $gateway->validWebhook($payload, $request->header('x-paystack-signature'))) {
            return response('Invalid signature', 401);
        }

        $body = json_decode($payload, true);
        if (! is_array($body) || ! isset($body['event'])) {
            return response('Bad payload', 400);
        }

        try {
            $event = WebhookEvent::create([
                'provider' => 'paystack',
                'event' => (string) $body['event'],
                'hash' => hash('sha256', $payload),
                'payload' => $body,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response('Already received', 200);
        }

        try {
            $handle->run($event);
        } catch (Throwable $e) {
            report($e);
            $event->forceFill(['error' => mb_substr($e->getMessage(), 0, 250)])->save();
        }

        return response('OK', 200);
    }
}
