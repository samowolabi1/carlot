<?php

namespace Tests;

use App\Domain\Billing\Gateways\PaymentGateways;
use App\Domain\Lots\Support\CurrentLot;
use App\Domain\Messaging\SmsGateway;
use App\Domain\Messaging\WhatsAppGateway;
use App\Domain\Push\Gateways\PushGateway;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakePaymentGateway;
use Tests\Support\FakePushGateway;
use Tests\Support\FakeSmsGateway;
use Tests\Support\FakeWhatsAppGateway;

abstract class TestCase extends BaseTestCase
{
    protected FakeSmsGateway $sms;

    protected FakeWhatsAppGateway $whatsapp;

    /** The Paystack fake (the default provider). */
    protected FakePaymentGateway $payments;

    protected FakePaymentGateway $flutterwave;

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
        // One fake per provider; PaymentGateway resolves to whichever the admin chose (Paystack by default).
        $this->payments = new FakePaymentGateway('paystack');
        $this->flutterwave = new FakePaymentGateway('flutterwave');
        $this->app->instance('payments.paystack', $this->payments);
        $this->app->instance('payments.sandbox', $this->payments);
        $this->app->instance('payments.flutterwave', $this->flutterwave);
        PaymentGateways::flush();
        $this->push = new FakePushGateway;
        $this->app->instance(PushGateway::class, $this->push);

        $this->runJobsWithoutSellerContext();

        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            $this->seed(PlanSeeder::class);
        }
    }

    /**
     * On the server, queued jobs and notifications run in a worker that has no current seller. Tests run them
     * synchronously inside the request, where the seller is set, which would hide a job that leans on it. So clear
     * it while each job runs, and put it back afterwards.
     */
    private function runJobsWithoutSellerContext(): void
    {
        $saved = [];
        Event::listen(JobProcessing::class, function () use (&$saved): void {
            $current = app(CurrentLot::class);
            $saved[] = $current->get();
            $current->set(null);
        });
        $restore = function () use (&$saved): void {
            if ($saved !== []) {
                app(CurrentLot::class)->set(array_pop($saved));
            }
        };
        Event::listen(JobProcessed::class, $restore);
        Event::listen(JobExceptionOccurred::class, $restore);
    }
}
