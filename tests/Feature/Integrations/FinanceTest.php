<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Partners\FinancePartner;
use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class);

beforeEach(function () {
    config(['lotlink.finance_partner.webhook_secret' => 'shh', 'lotlink.finance_partner.code' => 'demo']);
    $this->lot = Lot::factory()->active()->create(['name' => 'Prime Motors']);
    $this->car = $this->car($this->lot, 'Toyota', 'Camry', ['year' => 2018, 'price' => 1_000_000_000]);
    $this->buyer = User::factory()->create(['name' => 'Tunde Adebayo', 'phone' => '+2348035550777', 'email' => 'tunde@example.com']);
    $this->apply = fn (array $data = []) => $this->actingAs($this->buyer)->post(route('finance.store', $this->car->ulid), [
        'monthly_income' => '₦1,500,000', 'monthly_commitments' => '100000', 'employment' => 'salaried', 'employer' => 'Dangote',
        'deposit' => '3000000', 'tenor_months' => 36, 'consent' => true, ...$data,
    ]);
});

it('sends nothing without consent, then hands the application to the partner', function () {
    $this->actingAs($this->buyer)->get(route('finance.create', $this->car->ulid))->assertInertia(fn (Assert $page) => $page->component('Finance/Apply')->where('defaults.deposit', 3_000_000));

    ($this->apply)(['consent' => false])->assertSessionHasErrors('consent');
    expect(FinanceApplication::count())->toBe(0);

    ($this->apply)()->assertRedirect(route('finance.index'))->assertSessionHas('success');
    $application = FinanceApplication::sole();
    expect($application)->status->toBe('pre_approved')->amount->toBe(700_000_000)->tenor_months->toBe(36)
        ->external_ref->toStartWith('DEMO-')
        ->and($application->applicant)->toMatchArray(['monthly_income' => 1_500_000, 'employer' => 'Dangote'])
        ->and(DB::table('finance_applications')->value('applicant'))->not->toContain('Dangote') // encrypted at rest
        ->and($this->buyer->notifications()->where('data->kind', 'finance')->sole()->data['text'])->toContain('pre-approved a loan of ₦7,000,000');

    ($this->apply)(['monthly_income' => '200000'])->assertSessionHas('success');
    expect(FinanceApplication::latest('id')->first()->status)->toBe('declined');

    $this->actingAs($this->buyer)->get(route('finance.index'))->assertInertia(fn (Assert $page) => $page->has('applications', 2));
    $this->actingAs(User::factory()->create())->get(route('finance.index'))->assertInertia(fn (Assert $page) => $page->has('applications', 0));
});

it('marks the application failed and shares nothing when the partner is down', function () {
    $this->app->instance(FinancePartner::class, new class implements FinancePartner
    {
        public function code(): string
        {
            return 'demo';
        }

        public function name(): string
        {
            return 'Demo Finance';
        }

        public function submit(FinanceApplication $application): array
        {
            throw new RuntimeException('timeout');
        }
    });

    ($this->apply)()->assertSessionHas('error');
    expect(FinanceApplication::sole()->status)->toBe('failed');
});

it('takes signed status updates from the partner', function () {
    ($this->apply)(['monthly_income' => '200000']);
    $application = FinanceApplication::sole();
    $body = json_encode(['reference' => $application->external_ref, 'status' => 'pre_approved', 'message' => 'Approved after review', 'approved_amount' => 6500000]);

    $this->call('POST', route('webhooks.finance'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_LOTLINK_SIGNATURE' => 'bad'], $body)->assertUnauthorized();
    $this->call('POST', route('webhooks.finance'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_LOTLINK_SIGNATURE' => hash_hmac('sha256', $body, 'shh')], $body)->assertOk();

    expect($application->fresh())->status->toBe('pre_approved')->approved_amount->toBe(650_000_000)->partner_message->toBe('Approved after review');
});
