<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Enums\LenderIntegration;
use App\Domain\Finance\Enums\LenderStatus;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Notifications\LenderAlert;
use App\Domain\Lots\Models\Lot;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\LenderFixtures;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class, LenderFixtures::class);

beforeEach(function () {
    $this->lot = Lot::factory()->active()->create(['name' => 'Prime Motors', 'state' => 'Lagos']);
    $this->car = $this->car($this->lot, 'Toyota', 'Camry', ['year' => 2018, 'price' => 1_000_000_000]);
    $this->buyer = User::factory()->create(['name' => 'Tunde Adebayo', 'phone' => '+2348035550777', 'email' => 'tunde@example.com']);
    $this->demo = $this->lender(['name' => 'Demo Finance', 'integration' => LenderIntegration::Demo]);
    $this->apply = fn (array $data = []) => $this->actingAs($this->buyer)->post(route('finance.store', $this->car->ulid), [
        'lender' => $this->demo->slug, 'monthly_income' => '₦1,500,000', 'monthly_commitments' => '100000', 'employment' => 'salaried', 'employer' => 'Dangote',
        'deposit' => '3000000', 'tenor_months' => 36, 'consent' => true, ...$data,
    ]);
});

it('lists the active lenders and sends nothing without consent, then only to the lender the buyer picked', function () {
    $this->lender(['name' => 'Waiting Bank', 'status' => LenderStatus::Pending]);
    $this->actingAs($this->buyer)->get(route('finance.create', $this->car->ulid))->assertInertia(fn (Assert $page) => $page->component('Finance/Apply')
        ->where('defaults.deposit', 3_000_000)->has('lenders', 1)->where('lenders.0.name', 'Demo Finance')->where('lenders.0.in_state', true));

    ($this->apply)(['consent' => false])->assertSessionHasErrors('consent');
    ($this->apply)(['lender' => ''])->assertSessionHasErrors('lender');
    expect(FinanceApplication::count())->toBe(0);

    ($this->apply)()->assertSessionHas('success');
    $application = FinanceApplication::sole();
    expect($application)->status->toBe(FinanceStatus::PreApproved)->amount->toBe(700_000_000)->tenor_months->toBe(36)
        ->lender_id->toBe($this->demo->id)->external_ref->toStartWith('DEMO-')
        ->and($application->applicant)->toMatchArray(['monthly_income' => 1_500_000, 'employer' => 'Dangote'])
        ->and(DB::table('finance_applications')->value('applicant'))->not->toContain('Dangote') // encrypted at rest
        ->and($this->buyer->notifications()->where('data->kind', 'finance')->sole()->data['text'])->toContain('Demo Finance pre-approved a loan of ₦7,000,000');

    $other = $this->car($this->lot, 'Honda', 'Accord', ['price' => 1_000_000_000]);
    $this->actingAs($this->buyer)->post(route('finance.store', $other->ulid), [
        'lender' => $this->demo->slug, 'monthly_income' => '200000', 'monthly_commitments' => '0', 'employment' => 'salaried', 'deposit' => '3000000', 'tenor_months' => 36, 'consent' => true,
    ])->assertSessionHas('success');
    expect(FinanceApplication::latest('id')->first()->status)->toBe(FinanceStatus::Declined);
    $this->actingAs($this->buyer)->get(route('finance.index'))->assertInertia(fn (Assert $page) => $page->has('applications', 2));
    $this->actingAs($this->buyer)->get(route('finance.index'))->assertInertia(fn (Assert $page) => $page->has('applications', 2));
    $this->actingAs(User::factory()->create())->get(route('finance.index'))->assertInertia(fn (Assert $page) => $page->has('applications', 0));
    $this->actingAs(User::factory()->create())->get(route('finance.show', $application))->assertNotFound();
});

it('refuses lenders that are not active or do not lend for the car on those terms', function () {
    $picky = $this->lender(['name' => 'Picky Bank', 'min_deposit_percent' => 40, 'tenors' => [12], 'states' => ['Kano']]);
    $paused = $this->lender(['name' => 'Paused Bank', 'status' => LenderStatus::Suspended]);

    ($this->apply)(['lender' => $picky->slug])->assertSessionHasErrors('lender');
    ($this->apply)(['lender' => $paused->slug])->assertSessionHasErrors('lender');
    expect(FinanceApplication::count())->toBe(0);

    ($this->apply)()->assertSessionHas('success');
    ($this->apply)()->assertSessionHasErrors('lender'); // one open application per car and lender
});

it('marks the application failed and shares nothing when the lender cannot be reached', function () {
    $this->lenderAnswers(fn () => throw new RuntimeException('timeout'));
    Notification::fake();

    ($this->apply)()->assertSessionHas('error');
    expect(FinanceApplication::sole()->status)->toBe(FinanceStatus::Failed);
});

it('posts to an API lender and takes its signed updates, only for its own applications', function () {
    Http::fake(['https://api.bank.test/*' => Http::response(['reference' => 'BNK-77', 'status' => 'received'])]);
    $bank = $this->lender(['name' => 'API Bank', 'integration' => LenderIntegration::Api, 'api_url' => 'https://api.bank.test', 'api_key' => 'key-1', 'webhook_secret' => 'shh']);
    $other = $this->lender(['name' => 'Other Bank', 'integration' => LenderIntegration::Api, 'api_url' => 'https://api.other.test', 'api_key' => 'key-2', 'webhook_secret' => 'psst']);

    ($this->apply)(['lender' => $bank->slug])->assertSessionHas('success');
    $application = FinanceApplication::sole();
    expect($application)->status->toBe(FinanceStatus::Received)->external_ref->toBe('BNK-77');
    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.bank.test/applications' && $r->hasHeader('Authorization', 'Bearer key-1')
        && $r['applicant']['employer'] === 'Dangote' && $r['callback_url'] === route('webhooks.finance', $bank));

    $send = function ($lender, array $body, string $secret) {
        $raw = json_encode($body);

        return $this->call('POST', route('webhooks.finance', $lender), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_LOTLINK_SIGNATURE' => hash_hmac('sha256', $raw, $secret)], $raw);
    };
    $body = ['reference' => 'BNK-77', 'status' => 'approved', 'message' => 'Approved after review', 'approved_amount' => 6500000, 'rate' => 23.5, 'tenor_months' => 36];

    $send($bank, $body, 'wrong')->assertUnauthorized();
    $send($other, $body, 'psst')->assertNotFound(); // another lender's application
    $send($bank, $body, 'shh')->assertOk();

    expect($application->fresh())->status->toBe(FinanceStatus::Approved)->approved_amount->toBe(650_000_000)->offer_rate_bp->toBe(2350)
        ->partner_message->toBe('Approved after review')->decided_at->not->toBeNull();

    $send($bank, ['reference' => $application->ulid, 'status' => 'disbursed', 'disbursed_amount' => 6500000, 'disbursed_reference' => 'TRF-1'], 'shh')->assertOk();
    expect($application->fresh())->status->toBe(FinanceStatus::Disbursed)->disbursed_amount->toBe(650_000_000);
    $send($bank, ['reference' => 'BNK-77', 'status' => 'declined'], 'shh')->assertUnprocessable(); // closed
});

it('tells a portal lender\'s team about a new application', function () {
    Notification::fake();
    $officer = User::factory()->create(['email' => 'kemi@kobo.test']);
    $portal = $this->lender([], $officer);

    ($this->apply)(['lender' => $portal->slug])->assertSessionHas('success');

    expect(FinanceApplication::sole()->status)->toBe(FinanceStatus::Submitted);
    Notification::assertSentTo($officer, LenderAlert::class, fn (LenderAlert $n) => str_contains($n->text, 'New car loan application: Tunde A. for the 2018 Toyota Camry'));
});
