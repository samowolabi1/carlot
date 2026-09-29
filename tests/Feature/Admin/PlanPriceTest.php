<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\Notifications\BillingNotice;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Models\Plan;
use App\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Filament\Resources\PlanResource\Pages\ListPlans;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00');
    $this->admin = User::factory()->admin()->create();
    $this->pro = Plan::where('code', 'pro')->first();
    $this->pro->update(['provider_plan_code' => 'PLN_pro']);

    // A lot paying for Pro, renewing on 1 Nov.
    $this->owner = User::factory()->staff()->create(['email' => 'ada@primemotors.ng']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    Subscription::withoutGlobalScopes()->where('lot_id', $this->lot->id)->update(['plan_id' => $this->pro->id, 'status' => SubscriptionStatus::Active, 'current_period_end' => '2026-11-01 09:00']);
    $this->lot->update(['plan_id' => $this->pro->id]);
});

it('changes the price for new subscribers only: Paystack first, current subscribers keep theirs', function () {
    Notification::fake();
    $this->actingAs($this->admin);

    Livewire::test(ListPlans::class)
        ->assertTableColumnStateSet('paying', 1, $this->pro)
        ->callTableAction('price', $this->pro, ['price' => 50000, 'who' => 'new'])
        ->assertHasNoTableActionErrors();

    expect($this->pro->fresh()->price)->toBe(5_000_000)
        ->and($this->payments->updatedPlans)->toBe([['code' => 'PLN_pro', 'amount' => 5_000_000, 'existing' => false]])
        ->and(AuditLog::where('action', 'admin.plan_price_changed')->sole()->changes)->toMatchArray(['before' => 4_500_000, 'after' => 5_000_000]);
    Notification::assertNothingSent();

    // A lot subscribing now is charged the new price, matching Paystack's plan.
    $newOwner = User::factory()->staff()->create(['email' => 'new@lot.ng']);
    $newLot = app(CreateLot::class)->run($newOwner, ['name' => 'New Lot', 'phone' => '+2348021119999']);
    $this->actingAs($newOwner)->post(route('dealer.billing.checkout', $newLot), ['plan' => 'pro'], ['X-Inertia' => 'true']);
    expect(Payment::latest('id')->first()->amount)->toBe(5_000_000);
});

it('changes the price for everyone from their next renewal and tells paying lots', function () {
    Notification::fake();
    $this->actingAs($this->admin);

    Livewire::test(ListPlans::class)->callTableAction('price', $this->pro, ['price' => 55000, 'who' => 'all'])->assertHasNoTableActionErrors();

    expect($this->payments->updatedPlans[0]['existing'])->toBeTrue();
    Notification::assertSentTo($this->owner, BillingNotice::class, fn (BillingNotice $n) => str_contains($n->text, 'from ₦45,000 to ₦55,000 a month from your next renewal on 1 Nov 2026'));
});

it('changes nothing if Paystack refuses, and keeps the free plan free', function () {
    $this->actingAs($this->admin);
    $this->payments->failPlanUpdate = true;

    Livewire::test(ListPlans::class)->callTableAction('price', $this->pro, ['price' => 60000, 'who' => 'new'])
        ->assertNotified('Price not changed');
    expect($this->pro->fresh()->price)->toBe(4_500_000);

    $free = Plan::where('code', 'free')->first();
    Livewire::test(ListPlans::class)->assertTableActionHidden('price', $free);

    // The edit form can't change the price behind Paystack's back.
    Livewire::test(EditPlan::class, ['record' => $this->pro->getRouteKey()])
        ->fillForm(['price' => 1, 'name' => 'Pro'])->call('save')->assertHasNoFormErrors();
    expect($this->pro->fresh()->price)->toBe(4_500_000);
});

it('keeps plan prices to admins', function () {
    $this->actingAs($this->owner)->get('/admin/plans')->assertForbidden();
    $this->actingAs($this->admin)->get('/admin/plans')->assertOk()->assertSee('Change price');
});
