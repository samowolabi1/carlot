<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Models\Coupon;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Models\Plan;
use App\Filament\Resources\CouponResource\Pages\CreateCoupon;
use App\Filament\Resources\CouponResource\Pages\EditCoupon;
use App\Filament\Resources\CouponResource\Pages\ListCoupons;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-05 09:00');
    $this->admin = User::factory()->admin()->create();
    $this->starter = Plan::where('code', 'starter')->first();
    $this->owner = User::factory()->staff()->create();
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->redeem = fn (string $code, ?User $as = null, $lot = null) => $this->actingAs($as ?? $this->owner)
        ->post(route('dealer.billing.coupon', $lot ?? $this->lot), ['coupon' => $code]);
});

it('creates a code in capitals and checks its shape', function () {
    $this->actingAs($this->admin);

    Livewire::test(CreateCoupon::class)
        ->fillForm(['code' => 'kano meetup', 'plan_id' => $this->starter->id, 'trial_days' => 60])
        ->call('create')
        ->assertHasFormErrors(['code']);

    Livewire::test(CreateCoupon::class)
        ->fillForm(['code' => ' kano-meetup ', 'plan_id' => $this->starter->id, 'trial_days' => 60, 'max_redemptions' => 10, 'note' => 'Kano seller meetup'])
        ->call('create')
        ->assertHasNoFormErrors();

    $coupon = Coupon::sole();
    expect($coupon->code)->toBe('KANO-MEETUP')
        ->and($coupon->active)->toBeTrue()
        ->and($coupon->note)->toBe('Kano seller meetup')
        ->and(AuditLog::where('action', 'admin.coupon_created')->exists())->toBeTrue();

    ($this->redeem)('kano-meetup')->assertSessionHasNoErrors();
    expect($coupon->fresh()->redeemed)->toBe(1);
});

it('edits a code for future redemptions, never below what has been used', function () {
    $coupon = Coupon::create(['code' => 'LAUNCH3', 'plan_id' => $this->starter->id, 'trial_days' => 90, 'max_redemptions' => 5, 'redeemed' => 3]);
    $this->actingAs($this->admin);

    Livewire::test(EditCoupon::class, ['record' => $coupon->getRouteKey()])
        ->fillForm(['max_redemptions' => 2])
        ->call('save')
        ->assertHasFormErrors(['max_redemptions']);

    Livewire::test(EditCoupon::class, ['record' => $coupon->getRouteKey()])
        ->fillForm(['code' => 'launch-q4', 'trial_days' => 60, 'max_redemptions' => 50])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($coupon->fresh())->code->toBe('LAUNCH-Q4')->trial_days->toBe(60)->max_redemptions->toBe(50)->redeemed->toBe(3);
    $changes = AuditLog::where('action', 'admin.coupon_changed')->sole()->changes;
    expect($changes['before'])->toMatchArray(['code' => 'LAUNCH3', 'trial_days' => 90, 'max_redemptions' => 5])
        ->and($changes['after'])->toMatchArray(['code' => 'LAUNCH-Q4', 'trial_days' => 60, 'max_redemptions' => 50]);

    ($this->redeem)('LAUNCH3')->assertSessionHasErrors('coupon');
    ($this->redeem)('launch-q4')->assertSessionHasNoErrors();
    expect(Subscription::sole()->trial_ends_at->toDateString())->toBe('2026-12-04');
});

it('pauses and resumes a code', function () {
    $coupon = Coupon::create(['code' => 'LAUNCH3', 'plan_id' => $this->starter->id, 'trial_days' => 90]);
    $this->actingAs($this->admin);

    Livewire::test(ListCoupons::class)
        ->callTableAction('toggle', $coupon)
        ->assertTableColumnStateSet('state', 'Paused', $coupon);
    expect($coupon->fresh()->state())->toBe('paused');

    ($this->redeem)('LAUNCH3')->assertSessionHasErrors(['coupon' => 'That code is not valid or has been used up.']);

    $this->actingAs($this->admin);
    Livewire::test(ListCoupons::class)->callTableAction('toggle', $coupon->fresh());
    ($this->redeem)('LAUNCH3')->assertSessionHasNoErrors();

    expect(AuditLog::whereIn('action', ['admin.coupon_paused', 'admin.coupon_resumed'])->orderBy('id')->pluck('action')->all())
        ->toBe(['admin.coupon_paused', 'admin.coupon_resumed']);
});

it('shows who used a code and only deletes unused ones', function () {
    $used = Coupon::create(['code' => 'LAUNCH3', 'plan_id' => $this->starter->id, 'trial_days' => 90]);
    $unused = Coupon::create(['code' => 'SPARE', 'plan_id' => $this->starter->id, 'trial_days' => 30]);
    ($this->redeem)('LAUNCH3')->assertSessionHasNoErrors();

    $this->actingAs($this->admin);
    Livewire::test(ListCoupons::class)
        ->assertTableActionVisible('used', $used->fresh())
        ->assertTableActionHidden('used', $unused)
        ->mountTableAction('used', $used->fresh())
        ->assertSee('Prime Motors');

    Livewire::test(ListCoupons::class)
        ->assertTableActionHidden('delete', $used->fresh())
        ->callTableAction('delete', $unused);

    expect(Coupon::pluck('code')->all())->toBe(['LAUNCH3'])
        ->and(AuditLog::where('action', 'admin.coupon_deleted')->sole()->changes)->toBe(['code' => 'SPARE']);
});

it('filters codes by status', function () {
    $active = Coupon::create(['code' => 'OPEN', 'plan_id' => $this->starter->id, 'trial_days' => 90]);
    $paused = Coupon::create(['code' => 'PAUSED', 'plan_id' => $this->starter->id, 'trial_days' => 90, 'active' => false]);
    $expired = Coupon::create(['code' => 'OLD', 'plan_id' => $this->starter->id, 'trial_days' => 90, 'expires_at' => now()->subDay()]);
    $full = Coupon::create(['code' => 'FULL', 'plan_id' => $this->starter->id, 'trial_days' => 90, 'max_redemptions' => 1, 'redeemed' => 1]);
    $this->actingAs($this->admin);

    Livewire::test(ListCoupons::class)
        ->filterTable('state', 'active')->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$paused, $expired, $full])
        ->filterTable('state', 'paused')->assertCanSeeTableRecords([$paused])->assertCanNotSeeTableRecords([$active, $expired, $full])
        ->filterTable('state', 'expired')->assertCanSeeTableRecords([$expired])->assertCanNotSeeTableRecords([$active, $paused, $full])
        ->filterTable('state', 'used_up')->assertCanSeeTableRecords([$full])->assertCanNotSeeTableRecords([$active, $paused, $expired]);
});

it('keeps coupons to admins', function () {
    $this->actingAs($this->owner)->get('/admin/coupons')->assertForbidden();
    $this->actingAs($this->admin)->get('/admin/coupons')->assertOk();
});
