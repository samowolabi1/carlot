<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Models\Plan;

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00');
    $this->owner = User::factory()->staff()->create();
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors']);
    $this->lot->update(['status' => 'active']);
    $this->enforce = fn () => $this->artisan('subscriptions:enforce-limits')->assertSuccessful();
    $this->notices = fn () => $this->whatsapp->to($this->owner->phone, 'billing_update');
});

it('reminds three days before the trial ends, once', function () {
    ($this->enforce)();
    expect(($this->notices)())->toHaveCount(0);

    $this->travelTo('2026-10-17 09:00');
    ($this->enforce)();
    ($this->enforce)();

    expect(($this->notices)())->toHaveCount(1)
        ->and(($this->notices)()[0]->params[1])->toContain('ends on 19 Oct');
});

it('gives 7 days of grace after the trial, then moves to Free and hides extra cars', function () {
    // 12 live cars: the oldest available ones go above the Free limit of 10; reserved stay.
    $cars = collect(range(1, 11))->map(fn ($i) => Vehicle::factory()->available()->create(['lot_id' => $this->lot->id, 'listed_at' => now()->subDays(30 - $i)]));
    $reserved = Vehicle::factory()->available()->create(['lot_id' => $this->lot->id, 'listed_at' => now()->subDays(60)]);
    $reserved->forceFill(['status' => VehicleStatus::Reserved])->save();

    $this->travelTo('2026-10-19 10:00');
    ($this->enforce)();
    $subscription = Subscription::sole();
    expect($subscription->status)->toBe(SubscriptionStatus::PastDue)
        ->and($subscription->grace_ends_at->toDateString())->toBe('2026-10-26')
        ->and(Vehicle::where('status', VehicleStatus::Hidden)->count())->toBe(0);

    $this->travelTo('2026-10-26 11:00');
    ($this->enforce)();

    expect(Subscription::sole()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($this->lot->fresh()->plan->code)->toBe('free')
        ->and(Vehicle::withoutGlobalScopes()->where('status', VehicleStatus::Hidden)->pluck('id')->all())->toBe([$cars[0]->id, $cars[1]->id])
        ->and($reserved->fresh()->status)->toBe(VehicleStatus::Reserved)
        ->and(collect(($this->notices)())->last()->params[1])->toContain('2 cars were hidden');
});

it('moves a cancelled plan to Free when the paid month ends', function () {
    Subscription::sole()->update(['status' => SubscriptionStatus::Active, 'plan_id' => Plan::where('code', 'pro')->value('id'), 'current_period_end' => now()->addDays(10), 'trial_ends_at' => null, 'cancel_at_period_end' => now()]);

    $this->travel(9)->days();
    ($this->enforce)();
    expect(Subscription::sole()->status)->toBe(SubscriptionStatus::Active);

    $this->travel(2)->days();
    ($this->enforce)();
    expect(Subscription::sole()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($this->lot->fresh()->plan->code)->toBe('free');
});

it('marks a plan past due when no renewal arrives', function () {
    Subscription::sole()->update(['status' => SubscriptionStatus::Active, 'current_period_end' => now()->addDays(3), 'trial_ends_at' => null]);

    $this->travel(4)->days();
    ($this->enforce)();
    expect(Subscription::sole()->status)->toBe(SubscriptionStatus::Active); // a day's slack for Paystack

    $this->travel(1)->days();
    ($this->enforce)();
    expect(Subscription::sole()->status)->toBe(SubscriptionStatus::PastDue);
});
