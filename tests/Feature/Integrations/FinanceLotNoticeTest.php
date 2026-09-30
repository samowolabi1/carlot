<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Deals\Notifications\DealAlert;
use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Enums\LenderIntegration;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;
use App\Domain\Lots\Actions\CreateLot;
use Illuminate\Support\Facades\Notification;
use Tests\Support\LenderFixtures;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class, LenderFixtures::class);

/* The lot hears that a buyer applied for a car loan on its car, and about a pre-approval; never the private details. */

beforeEach(function () {
    $this->owner = User::factory()->staff()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active']);
    $this->car = $this->car($this->lot, 'Toyota', 'Camry', ['year' => 2018, 'price' => 1_000_000_000]);
    $this->buyer = User::factory()->create(['name' => 'Tunde Adebayo', 'phone' => '+2348035550777']);
    $this->bank = $this->lender(['name' => 'Demo Finance', 'integration' => LenderIntegration::Api, 'api_url' => 'https://api.bank.test', 'api_key' => 'k', 'webhook_secret' => 'shh']);
    $this->apply = fn () => $this->actingAs($this->buyer)->post(route('finance.store', $this->car->ulid), [
        'monthly_income' => '₦1,500,000', 'monthly_commitments' => '100000', 'employment' => 'salaried', 'employer' => 'Dangote',
        'deposit' => '3000000', 'tenor_months' => 36, 'consent' => true, 'lender' => $this->bank->slug,
    ])->assertSessionHasNoErrors();
    // Like a real partner: it takes the application ("received") and answers later through the signed webhook.
    $this->partner = fn (string $status = 'received', ?int $approved = null) => $this->lenderAnswers(
        fn (FinanceApplication $application) => ['reference' => 'REF-'.$application->ulid, 'status' => $status, 'approved_amount' => $approved]
    );
    ($this->partner)();
    $this->webhook = function (array $body) {
        $raw = json_encode($body);

        return $this->call('POST', route('webhooks.finance', $this->bank), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_LOTLINK_SIGNATURE' => hash_hmac('sha256', $raw, 'shh')], $raw);
    };
});

it('gives the lot a car-loan lead, a line in the chat and an alert, without the private details', function () {
    Notification::fake();

    ($this->apply)();

    $lead = Lead::withoutGlobalScopes()->sole();
    expect($lead)->source->toBe(LeadSource::Finance)->vehicle_id->toBe($this->car->id);
    $line = Message::query()->where('side', Message::SYSTEM)->latest('id')->value('body');
    expect($line)->toBe('Applied for a car loan on the 2018 Toyota Camry (36 months).')
        ->not->toContain('1,500,000')->not->toContain('Dangote');
    Notification::assertSentTo($this->owner, DealAlert::class, fn (DealAlert $n) => $n->kind === 'finance'
        && $n->text === 'Tunde A. applied for a car loan on the 2018 Toyota Camry.'
        && $n->url === route('dealer.leads.show', [$this->lot, $lead]));
});

it('tells the lot about a pre-approval, but not about a decline', function () {
    ($this->apply)();
    $application = FinanceApplication::sole();
    Notification::fake();

    ($this->webhook)(['reference' => $application->external_ref, 'status' => 'pre_approved', 'approved_amount' => 6_500_000])->assertOk();

    Notification::assertSentTo($this->owner, DealAlert::class, fn (DealAlert $n) => $n->text === 'Tunde A. is pre-approved for a car loan for ₦6,500,000 on the 2018 Toyota Camry.');
    expect(Message::query()->where('side', Message::SYSTEM)->latest('id')->value('body'))->toBe('Pre-approved for a car loan for ₦6,500,000 on the 2018 Toyota Camry.')
        ->and(Lead::withoutGlobalScopes()->count())->toBe(1); // the same lead, not a new one

    $other = $this->car($this->lot, 'Honda', 'Accord', ['price' => 800_000_000]);
    $this->actingAs($this->buyer)->post(route('finance.store', $other->ulid), [
        'monthly_income' => '₦1,500,000', 'monthly_commitments' => '100000', 'employment' => 'salaried', 'deposit' => '1000000', 'tenor_months' => 24, 'consent' => true, 'lender' => $this->bank->slug,
    ]);
    Notification::fake();
    ($this->webhook)(['reference' => FinanceApplication::latest('id')->first()->external_ref, 'status' => 'declined'])->assertOk();

    Notification::assertNotSentTo($this->owner, DealAlert::class);
    expect(Message::query()->where('body', 'like', '%declined%')->exists())->toBeFalse();
});

it('tells the lot straight away when the partner pre-approves on the spot', function () {
    ($this->partner)('pre_approved', 700_000_000);
    Notification::fake();

    ($this->apply)();

    expect(Message::query()->where('side', Message::SYSTEM)->orderBy('id')->pluck('body')->all())->toBe([
        'Applied for a car loan on the 2018 Toyota Camry (36 months).',
        'Pre-approved for a car loan for ₦7,000,000 on the 2018 Toyota Camry.',
    ]);
    Notification::assertSentToTimes($this->owner, DealAlert::class, 2);
});

it('tells the lot nothing when the partner could not be reached', function () {
    $this->lenderAnswers(fn () => throw new RuntimeException('down'));
    Notification::fake();

    ($this->apply)();

    expect(FinanceApplication::sole()->status)->toBe(FinanceStatus::Failed)->and(Lead::withoutGlobalScopes()->count())->toBe(0);
    Notification::assertNotSentTo($this->owner, DealAlert::class);
});
