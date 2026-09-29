<?php

namespace Tests;

use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Messaging\SmsGateway;
use App\Domain\Messaging\WhatsAppGateway;
use App\Domain\Push\Gateways\PushGateway;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakePaymentGateway;
use Tests\Support\FakePushGateway;
use Tests\Support\FakeSmsGateway;
use Tests\Support\FakeWhatsAppGateway;

abstract class TestCase extends BaseTestCase
{
    protected FakeSmsGateway $sms;

    protected FakeWhatsAppGateway $whatsapp;

    protected FakePaymentGateway $payments;

    protected FakePushGateway $push;

    /** The last one-time code sent to a phone: WhatsApp by default, SMS when WhatsApp wasn't used. */
    protected function lastCode(string $phone): ?string
    {
        $whatsapp = $this->whatsapp->to($phone, 'login_code');

        return $whatsapp === [] ? $this->sms->lastCodeFor($phone) : end($whatsapp)->params[0];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->sms = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $this->sms);
        $this->whatsapp = new FakeWhatsAppGateway;
        $this->app->instance(WhatsAppGateway::class, $this->whatsapp);
        $this->payments = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->payments);
        $this->push = new FakePushGateway;
        $this->app->instance(PushGateway::class, $this->push);

        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            $this->seed(PlanSeeder::class);
        }
    }
}
