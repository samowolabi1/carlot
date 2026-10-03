<?php

use App\Domain\Push\Gateways\WebPushGateway;
use App\Domain\Push\Models\PushSubscription;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\VAPID;

/** A browser's push keys, as PushManager.subscribe() would give them (P-256 public key, 16-byte auth secret). */
function browserKeys(): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $ec = openssl_pkey_get_details($key)['ec'];
    $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

    return ['p256dh' => $b64("\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)), 'auth' => $b64(random_bytes(16))];
}

it('signs with VAPID, encrypts the payload, and reports devices that are gone', function () {
    $vapid = VAPID::createVapidKeys();
    $sent = [];
    $stack = HandlerStack::create(new MockHandler([new Response(201), new Response(410)]));
    $stack->push(Middleware::history($sent));

    $devices = collect(['https://fcm.googleapis.com/fcm/send/live', 'https://fcm.googleapis.com/fcm/send/gone'])->map(function (string $endpoint) {
        $keys = browserKeys();

        return new PushSubscription(['endpoint' => $endpoint, 'public_key' => $keys['p256dh'], 'auth_token' => $keys['auth'], 'content_encoding' => 'aes128gcm']);
    });

    $gateway = new WebPushGateway('mailto:support@caryardng.com', $vapid['publicKey'], $vapid['privateKey'], new Client(['handler' => $stack]));
    $gone = $gateway->send($devices, ['title' => 'Prime Motors', 'body' => 'Yes, come and see it', 'url' => 'https://caryardng.com/c/1', 'tag' => 'chat-1']);

    expect($gone)->toBe(['https://fcm.googleapis.com/fcm/send/gone'])->and($sent)->toHaveCount(2);

    $request = $sent[0]['request'];
    expect((string) $request->getUri())->toBe('https://fcm.googleapis.com/fcm/send/live')
        ->and($request->getHeaderLine('Content-Encoding'))->toBe('aes128gcm')
        ->and($request->getHeaderLine('TTL'))->toBe('86400')
        ->and($request->getHeaderLine('Authorization'))->toStartWith('vapid t=')->toContain(', k='.$vapid['publicKey'])
        ->and((string) $request->getBody())->not->toContain('Prime Motors'); // encrypted, only the browser can read it
});
