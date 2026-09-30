<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Finance\Enums\LenderIntegration;
use App\Domain\Finance\Enums\LenderRole;
use App\Domain\Finance\Enums\LenderStatus;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Models\Lender;
use App\Domain\Finance\Notifications\LenderAlert;
use App\Domain\Lots\Models\Lot;
use App\Filament\Resources\FinanceApplicationResource\Pages\ListFinanceApplications;
use App\Filament\Resources\LenderResource\Pages\ListLenders;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\LenderFixtures;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class, LenderFixtures::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('shows lenders and car loan applications to admins only', function () {
    $this->lender(['name' => 'Kobo Motor Finance']);
    $this->actingAs(User::factory()->create())->get('/admin/lenders')->assertForbidden();
    $this->actingAs($this->admin)->get('/admin/lenders')->assertOk()->assertSee('Kobo Motor Finance');
    $this->actingAs($this->admin)->get('/admin/finance-applications')->assertOk();
});

it('onboards a lender with its first admin, active at once', function () {
    Notification::fake();
    $this->actingAs($this->admin);

    Livewire::test(ListLenders::class)->callAction('onboard', [
        'name' => 'Access Car Loans', 'licence_type' => 'commercial_bank', 'licence_number' => 'CBN-001', 'contact_name' => 'Ada Obi',
        'contact_phone' => '0803 111 2222', 'contact_email' => 'ada@access.test', 'rate' => 21, 'min_amount' => 1_000_000, 'max_amount' => 60_000_000,
        'min_deposit_percent' => 20, 'tenors' => ['12', '24', '36'], 'states' => [], 'integration' => 'portal',
        'admin_name' => 'Ada Obi', 'admin_email' => 'Ada@Access.test',
    ])->assertHasNoActionErrors();

    $lender = Lender::where('name', 'Access Car Loans')->sole();
    $ada = User::where('email', 'ada@access.test')->sole();
    expect($lender)->status->toBe(LenderStatus::Active)->slug->toBe('access-car-loans')->tenors->toBe([12, 24, 36])->states->toBeNull()
        ->integration->toBe(LenderIntegration::Portal)->reviewed_by->toBe($this->admin->id)
        ->and($lender->roleOf($ada))->toBe(LenderRole::Admin);
    Notification::assertSentTo($ada, LenderAlert::class, fn (LenderAlert $n) => $n->url === route('lender.home'));
});

it('approves, rejects and pauses lenders from the list', function () {
    Notification::fake();
    $this->actingAs($this->admin);
    $pending = $this->lender(['name' => 'Waiting Bank', 'status' => LenderStatus::Pending], User::factory()->create());
    $other = $this->lender(['name' => 'Other Bank', 'status' => LenderStatus::Pending]);

    Livewire::test(ListLenders::class)
        ->callTableAction('approve', $pending)
        ->callTableAction('reject', $other, ['note' => ''])->assertHasTableActionErrors(['note'])
        ->callTableAction('reject', $other, ['note' => 'We could not find the licence number.']);

    expect($pending->fresh()->status)->toBe(LenderStatus::Active)->and($other->fresh())->status->toBe(LenderStatus::Rejected)->review_note->toBe('We could not find the licence number.');

    Livewire::test(ListLenders::class)->callTableAction('suspend', $pending->fresh(), ['note' => 'Complaints from buyers.']);
    expect($pending->fresh()->status)->toBe(LenderStatus::Suspended);
});

it('lists every application without the buyer\'s private details', function () {
    $lot = Lot::factory()->active()->create();
    $car = $this->car($lot, 'Toyota', 'Camry', ['price' => 1_000_000_000]);
    $lender = $this->lender(['integration' => LenderIntegration::Demo]);
    $this->actingAs(User::factory()->create(['name' => 'Tunde Adebayo']))->post(route('finance.store', $car->ulid), [
        'lender' => $lender->slug, 'monthly_income' => '1500000', 'monthly_commitments' => '0', 'employment' => 'salaried', 'employer' => 'Dangote',
        'deposit' => '3000000', 'tenor_months' => 36, 'consent' => true,
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin);
    Livewire::test(ListFinanceApplications::class)->assertCanSeeTableRecords(FinanceApplication::all())
        ->assertSee('Tunde Adebayo')->assertSee('Pre-approved')->assertDontSee('Dangote')->assertDontSee('1,500,000');
});
