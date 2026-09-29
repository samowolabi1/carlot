<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Billing\Actions\HandleFlutterwaveEvent;
use App\Domain\Billing\Gateways\PaymentGateways;
use App\Domain\Billing\Models\WebhookEvent;
use App\Http\Controllers\Controller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * POST /webhooks/flutterwave: checks the verif-hash header, stores the event, handles each delivery
 * once. Flutterwave retries anything that doesn't get a 200, so errors after storing still answer 200.
 */
class FlutterwaveWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentGateways $gateways, HandleFlutterwaveEvent $handle): Response
    {
        $payload = $request->getContent();

        if (! $gateways->for('flutterwave')->validWebhook($payload, $request->header('verif-hash'))) {
            return response('Invalid signature', 401);
        }

        $body = json_decode($payload, true);
        if (! is_array($body) || ! isset($body['event'])) {
            return response('Bad payload', 400);
        }

        try {
            $event = WebhookEvent::create([
                'provider' => 'flutterwave',
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
