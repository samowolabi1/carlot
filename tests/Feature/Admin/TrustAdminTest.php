<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\Impersonation;
use App\Domain\Admin\PlatformMetrics;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Models\Plan;
use App\Filament\Resources\LotResource\Pages\ListLots;
use App\Filament\Resources\UserResource\Pages\EditUser;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class);

beforeEach(function () {
    $this->travelTo('2026-10-05 12:00');
    $this->admin = User::factory()->admin()->create(['name' => 'Ops']);
    $this->owner = User::factory()->staff()->create(['name' => 'Emeka Nwosu', 'phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active']);
});

it('shows the review queue, listings, reports, reviews and audit log to admins only', function () {
    $this->car($this->lot, 'Toyota', 'Camry');
    $pages = ['/admin', '/admin/lot-verifications', '/admin/fraud-signals', '/admin/reports', '/admin/reviews', '/admin/vehicles', '/admin/audit-logs'];

    foreach ($pages as $page) {
        $this->actingAs($this->owner)->get($page)->assertForbidden();
    }
    foreach ($pages as $page) {
        $this->actingAs($this->admin)->get($page)->assertOk();
    }
    $this->actingAs($this->admin)->get('/admin')->assertSee('Review queue')->assertSee('Live listings');
});

it('counts platform metrics for the last 30 days', function () {
    $this->car($this->lot, 'Toyota', 'Camry');
    Appointment::factory()->create(['lot_id' => $this->lot->id]);
    $pro = Plan::where('code', 'pro')->firstOrFail();
    Subscription::where('lot_id', $this->lot->id)->update(['plan_id' => $pro->id, 'status' => 'active']);

    $m = PlatformMetrics::summary();
    expect($m)->active_lots->toBe(1)->live_listings->toBe(1)->bookings->toBe(1)->mrr->toBe($pro->price)->churn->toEqual(0.0);
});

it('lets an admin log in as a seller for support, and switch back, with both in the audit log', function () {
    $this->actingAs($this->admin);
    Livewire::test(ListLots::class)->callTableAction('impersonate', $this->lot)->assertRedirect(route('dealer.dashboard', $this->lot));

    expect(auth()->id())->toBe($this->owner->id)->and(session(Impersonation::SESSION_KEY))->toBe($this->admin->id);
    $this->get(route('dealer.dashboard', $this->lot))->assertInertia(fn (Assert $page) => $page->where('impersonating', true));

    $this->post(route('impersonation.stop'))->assertRedirect('/admin');
    expect(auth()->id())->toBe($this->admin->id)
        ->and(AuditLog::where('action', 'like', 'admin.impersonation_%')->orderBy('id')->pluck('action')->all())->toBe(['admin.impersonation_started', 'admin.impersonation_ended']);

    // Not into another admin, and never without being an admin.
    expect(fn () => app(Impersonation::class)->start($this->admin, User::factory()->admin()->create()))->toThrow(ValidationException::class);
    expect(fn () => app(Impersonation::class)->start($this->owner, User::factory()->create()))->toThrow(ValidationException::class);
});

it('registers independent inspectors from the user page', function () {
    $user = User::factory()->create(['name' => 'Tayo Bello']);

    $this->actingAs($this->admin);
    Livewire::test(EditUser::class, ['record' => $user->ulid])
        ->fillForm(['is_inspector' => true, 'inspector_company' => 'AutoCheck NG'])
        ->call('save')->assertHasNoFormErrors();

    expect($user->fresh())->isInspector()->toBeTrue()->inspector_company->toBe('AutoCheck NG')
        ->and(AuditLog::where('action', 'admin.inspector_added')->exists())->toBeTrue();
});

it('lets admins put a seller on any plan, such as Enterprise', function () {
    $this->actingAs($this->admin);
    Livewire::test(ListLots::class)->callTableAction('plan', $this->lot, ['plan_id' => Plan::where('code', 'enterprise')->value('id')]);

    expect($this->lot->fresh()->planAllows('bulk_import'))->toBeTrue()
        ->and(AuditLog::where('action', 'admin.plan_set')->sole()->changes['to'])->toBe('enterprise');
});
