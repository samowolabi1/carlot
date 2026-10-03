<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Models\Instalment;
use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'UTC')); // 11:00 Lagos
    $this->owner = User::factory()->staff()->create();
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->car = Vehicle::factory()->available()->create(['lot_id' => $this->lot->id, 'price' => 1_000_000_000]); // ₦10m

    $this->actingAs($this->owner)->post(route('dealer.manager.orders.store', $this->lot), [
        'vehicle' => $this->car->ulid, 'name' => 'Ada Obi', 'phone' => '08035550101', 'consent_whatsapp' => true,
    ]);
    $this->order = SalesOrder::withoutGlobalScopes()->sole();
    $this->pay = fn (string $amount) => $this->actingAs($this->owner)->post(route('dealer.manager.orders.payments.store', [$this->lot, $this->order]), ['amount' => $amount, 'method' => 'transfer']);
    $this->plan = fn (array $data = []) => $this->actingAs($this->owner)->put(route('dealer.manager.orders.instalments.update', [$this->lot, $this->order]), [
        'count' => 3, 'first_due' => '2026-11-05', 'frequency' => 'monthly', ...$data,
    ]);
    $this->statuses = fn () => Instalment::query()->orderBy('sequence')->get()->map(fn ($i) => $i->status->value.':'.intdiv($i->paid_amount, 100))->all();
});

it('splits what is left into dated instalments, the last taking the remainder', function () {
    ($this->pay)('3,000,000');
    ($this->plan)()->assertSessionHasNoErrors();

    $plan = Instalment::query()->orderBy('sequence')->get();
    expect($plan->pluck('amount')->all())->toBe([233_333_300, 233_333_300, 233_333_400])
        ->and($plan->map(fn ($i) => $i->due_date->toDateString())->all())->toBe(['2026-11-05', '2026-12-05', '2027-01-05'])
        ->and($this->order->fresh())->payment_plan->toBe('instalments')->instalments_from_paid->toBe(300_000_000);

    $this->actingAs($this->owner)->get(route('dealer.manager.orders.show', [$this->lot, $this->order]))
        ->assertInertia(fn (Assert $page) => $page->has('instalments', 3)->where('instalments.0.amount', '₦2,333,333')->where('plan.allowed', true));
});

it('applies payments to the oldest instalment first, and a void puts it back', function () {
    ($this->plan)(['count' => 2, 'frequency' => 'weekly', 'first_due' => '2026-10-12']);
    ($this->pay)('6,000,000');
    expect(($this->statuses)())->toBe(['paid:5000000', 'part_paid:1000000']);

    $payment = OrderPayment::query()->sole();
    $this->actingAs($this->owner)->post(route('dealer.manager.payments.void', [$this->lot, $payment]), ['reason' => 'Bounced'])->assertSessionHasNoErrors();
    expect(($this->statuses)())->toBe(['pending:0', 'pending:0']);
});

it('marks unpaid instalments overdue after their date in the seller\'s time', function () {
    ($this->plan)(['count' => 2, 'frequency' => 'weekly', 'first_due' => '2026-10-06']);

    $this->travelTo(CarbonImmutable::parse('2026-10-06 22:00', 'UTC')); // 23:00 Lagos, still the 6th
    $this->artisan('manager:mark-overdue');
    expect(($this->statuses)()[0])->toBe('pending:0');

    $this->travelTo(CarbonImmutable::parse('2026-10-06 23:30', 'UTC')); // 00:30 Lagos on the 7th
    $this->artisan('manager:mark-overdue');
    expect(($this->statuses)()[0])->toBe('overdue:0');

    $this->actingAs($this->owner)->get(route('dealer.manager.today', $this->lot))->assertInertia(fn (Assert $page) => $page->where('overdue.0.amount', '₦5,000,000'));

    // Paying it clears it.
    ($this->pay)('5,000,000');
    expect(($this->statuses)()[0])->toBe('paid:5000000');
});

it('reminds the customer 3 days before and on the day, from 09:00 lot time, once each', function () {
    ($this->plan)(['count' => 1, 'first_due' => '2026-10-10']);

    $this->travelTo(CarbonImmutable::parse('2026-10-07 07:30', 'UTC')); // 08:30 Lagos
    $this->artisan('manager:instalment-reminders');
    expect($this->whatsapp->to('+2348035550101', 'instalment_reminder'))->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2026-10-07 08:05', 'UTC')); // 09:05 Lagos
    $this->artisan('manager:instalment-reminders');
    $this->artisan('manager:instalment-reminders');
    $sent = $this->whatsapp->to('+2348035550101', 'instalment_reminder');
    expect($sent)->toHaveCount(1)->and($sent[0]->params)->toBe(['Ada Obi', '₦10,000,000', $this->car->title(), 'Prime Motors', 'Sat 10 Oct']);

    $this->travelTo(CarbonImmutable::parse('2026-10-10 08:05', 'UTC'));
    $this->artisan('manager:instalment-reminders');
    expect($this->whatsapp->to('+2348035550101', 'instalment_reminder'))->toHaveCount(2)
        ->and($this->whatsapp->to('+2348035550101', 'instalment_reminder')[1]->params[4])->toBe('today');
});

it('does not message customers without WhatsApp consent', function () {
    $this->order->customer()->first()->update(['consent_whatsapp' => false]);
    ($this->plan)(['count' => 1, 'first_due' => '2026-10-05']);
    $this->artisan('manager:instalment-reminders');

    expect($this->whatsapp->to('+2348035550101'))->toBe([])->and($this->sms->sent)->toBe([]);
});

it('shows the plan on the customer\'s tracking page', function () {
    ($this->plan)(['count' => 2]);

    $this->get(URL::signedRoute('orders.track', ['order' => $this->order->ulid]))
        ->assertInertia(fn (Assert $page) => $page->has('instalments', 2)->where('next.amount', '₦5,000,000')->where('next.overdue', false));
});

it('keeps plans to Starter and up, owners and managers, and sensible input', function () {
    ($this->plan)(['count' => 25])->assertSessionHasErrors('count');
    ($this->plan)(['first_due' => '2026-10-01'])->assertSessionHasErrors('first_due');

    $sales = User::factory()->staff()->create();
    $this->lot->members()->attach($sales, ['role' => LotRole::Sales->value, 'accepted_at' => now()]);
    $this->actingAs($sales)->put(route('dealer.manager.orders.instalments.update', [$this->lot, $this->order]), ['count' => 2, 'first_due' => '2026-11-05', 'frequency' => 'monthly'])->assertForbidden();

    $this->lot->update(['plan_id' => Plan::where('code', 'free')->value('id')]);
    ($this->plan)()->assertSessionHasErrors('count');

    $this->lot->update(['plan_id' => Plan::where('code', 'starter')->value('id')]);
    ($this->plan)()->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->delete(route('dealer.manager.orders.instalments.destroy', [$this->lot, $this->order]))->assertSessionHasNoErrors();
    expect(Instalment::count())->toBe(0)->and($this->order->fresh()->payment_plan)->toBe('full');
});
