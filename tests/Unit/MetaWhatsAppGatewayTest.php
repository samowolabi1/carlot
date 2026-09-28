<?php

use App\Domain\Messaging\Message;
use App\Domain\Messaging\MetaWhatsAppGateway;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

it('sends a template message with body variables and a URL button', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

    (new MetaWhatsAppGateway('token-123', '555000', 'v21.0', 'en'))->send('+2348035550001', new Message(
        'booking_confirmed', ['Chioma', 'Test drive · 2018 Toyota Camry SE', 'Prime Motors', 'Tue 6 Oct, 10:30'], 'fallback', 'bookings/01abc?signature=x',
    ));

    Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v21.0/555000/messages'
        && $request->hasHeader('Authorization', 'Bearer token-123')
        && $request['to'] === '2348035550001'
        && $request['template']['name'] === 'booking_confirmed'
        && $request['template']['language'] === ['code' => 'en']
        && $request['template']['components'][0]['parameters'][3] === ['type' => 'text', 'text' => 'Tue 6 Oct, 10:30']
        && $request['template']['components'][1] === ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => 'bookings/01abc?signature=x']]]);
});

it('repeats the code on the copy-code button of authentication templates', function () {
    Http::fake(['graph.facebook.com/*' => Http::response([])]);

    (new MetaWhatsAppGateway('t', '1'))->send('+2348035550001', new Message('login_code', ['482913'], 'x', authentication: true));

    Http::assertSent(fn ($request) => $request['template']['components'][1]['parameters'][0]['text'] === '482913');
});

it('throws on API errors so the message falls back to SMS', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template not approved']], 400)]);

    (new MetaWhatsAppGateway('t', '1'))->send('+2348035550001', new Message('booking_confirmed', [], 'x'));
})->throws(RequestException::class);
