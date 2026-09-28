<?php

namespace Tests;

use App\Domain\Messaging\SmsGateway;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeSmsGateway;

abstract class TestCase extends BaseTestCase
{
    protected FakeSmsGateway $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->sms = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $this->sms);

        if (in_array(RefreshDatabase::class, class_uses_recursive($this), true)) {
            $this->seed(PlanSeeder::class);
        }
    }
}
