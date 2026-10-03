<?php

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Admin\Actions\ManageAdminTeam;
use App\Domain\Admin\AdminArea;
use App\Domain\Admin\AdminRole;
use App\Domain\Admin\Impersonation;
use App\Domain\Admin\Notifications\AdminInvitation;
use App\Domain\Audit\AuditLog;
use App\Domain\Lots\Actions\CreateLot;
use App\Filament\Resources\AdminTeamResource\Pages\ListAdminTeam;
use App\Filament\Resources\LotResource\Pages\ListLots;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->adminAs(AdminRole::Owner)->create(['name' => 'Ada Owner']);
});

it('opens only each role\'s part of /admin, on the server, not just the menu', function () {
    $pages = [
        '/admin' => ['owner', 'operations', 'support', 'finance', 'viewer'],
        '/admin/admin-team' => ['owner'],
        '/admin/settings/payments' => ['owner'],
        '/admin/settings/advert-prices' => ['owner'],
        '/admin/message-templates' => ['owner'],
        '/admin/audit-logs' => ['owner'],
        '/admin/system-health' => ['owner'],
        '/admin/lot-verifications' => ['owner', 'operations'],
        '/admin/vehicles' => ['owner', 'operations'],
        '/admin/reports' => ['owner', 'operations'],
        '/admin/ad-campaigns' => ['owner', 'operations'],
        '/admin/makes' => ['owner', 'operations'],
        '/admin/lots' => ['owner', 'operations', 'support', 'finance'],
        '/admin/lenders' => ['owner', 'operations', 'finance'],
        '/admin/support-tickets' => ['owner', 'support'],
        '/admin/users' => ['owner', 'support'],
        '/admin/broadcasts' => ['owner', 'support'],
        '/admin/payments' => ['owner', 'finance'],
        '/admin/plans' => ['owner', 'finance'],
        '/admin/coupons' => ['owner', 'finance'],
        '/admin/settings/finance' => ['owner', 'finance'],
        '/admin/finance-applications' => ['owner', 'finance'],
    ];

    foreach (AdminRole::cases() as $role) {
        $admin = User::factory()->adminAs($role)->create();
        $this->flushSession(); // a fresh sign-in per role (the panel ties a session to one user's password hash)
        foreach ($pages as $url => $roles) {
            $status = $this->actingAs($admin)->get($url)->status();
            expect($status)->toBe(in_array($role->value, $roles, true) ? 200 : 403, "{$role->value} on {$url}");
        }
    }
});

it('lets owners invite an admin, who sets a password from the emailed link', function () {
    Notification::fake();
    $this->actingAs($this->owner);

    Livewire::test(ListAdminTeam::class)->callAction('invite', ['name' => 'Bola Support', 'email' => 'Bola@CarYardNG.com', 'role' => 'support'])->assertHasNoActionErrors();

    $bola = User::where('email', 'bola@caryardng.com')->sole();
    expect($bola)->role->toBe(UserRole::Admin)->admin_role->toBe(AdminRole::Support)->password->toBeNull()->invited_by->toBe($this->owner->id)
        ->and(AuditLog::where('action', 'admin.team_invited')->exists())->toBeTrue();
    Notification::assertSentTo($bola, AdminInvitation::class, fn (AdminInvitation $n) => $n->role === AdminRole::Support);

    // An email that already has an account (a buyer, a seller) can't be turned into an admin.
    expect(fn () => app(ManageAdminTeam::class)->invite($this->owner, 'X', 'bola@caryardng.com', AdminRole::Viewer))->toThrow(ValidationException::class);

    auth()->logout();
    $link = URL::temporarySignedRoute('admin.invitation', now()->addDays(7), ['user' => $bola->ulid]);
    $this->get(route('admin.invitation', ['user' => $bola->ulid]))->assertForbidden(); // unsigned
    $this->get($link)->assertOk()->assertInertia(fn ($page) => $page->component('Auth/AdminInvitation')->where('role', 'Support'));
    $this->post($link, ['name' => 'Bola Support', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
    $this->post($link, ['name' => 'Bola Support', 'password' => 'lotlink-2027', 'password_confirmation' => 'lotlink-2027'])->assertRedirect(url('/admin'));

    $this->assertAuthenticatedAs($bola->fresh());
    expect($bola->fresh()->password)->not->toBeNull();
    $this->get($link)->assertStatus(410); // used
});

it('only lets owners run the team, never on themselves, and always keeps an owner', function () {
    $team = app(ManageAdminTeam::class);
    $support = User::factory()->adminAs(AdminRole::Support)->create();
    $finance = User::factory()->adminAs(AdminRole::Finance)->create();

    expect(fn () => $team->changeRole($support, $finance, AdminRole::Owner))->toThrow(ValidationException::class)
        ->and(fn () => $team->changeRole($this->owner, $this->owner, AdminRole::Viewer))->toThrow(ValidationException::class)
        ->and(fn () => $team->remove($this->owner, $this->owner))->toThrow(ValidationException::class);

    $team->changeRole($this->owner, $finance, AdminRole::Owner);
    expect($finance->fresh()->admin_role)->toBe(AdminRole::Owner)
        ->and(AuditLog::where('action', 'admin.team_role_changed')->latest('id')->value('changes'))->toMatchArray(['from' => 'finance', 'to' => 'owner']);

    // With two owners one can step the other down, but not the last one.
    $team->changeRole($finance->fresh(), $this->owner, AdminRole::Viewer);
    expect(fn () => $team->changeRole($support->fresh()->forceFill(['admin_role' => AdminRole::Owner]), $finance->fresh(), AdminRole::Viewer))
        ->toThrow(ValidationException::class);
});

it('signs a removed admin out everywhere and takes away their access', function () {
    $ops = User::factory()->adminAs(AdminRole::Operations)->create();
    $ops->forceFill(['two_factor_secret' => 'x', 'two_factor_confirmed_at' => now()])->save();
    $ops->createToken('app');
    if (config('session.driver') === 'database') {
        DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $ops->id, 'payload' => '', 'last_activity' => time()]);
    }

    $this->actingAs($this->owner);
    Livewire::test(ListAdminTeam::class)->callTableAction('remove', $ops)->assertHasNoTableActionErrors();

    $ops->refresh();
    expect($ops)->role->toBe(UserRole::Customer)->admin_role->toBeNull()->password->toBeNull()->two_factor_secret->toBeNull()
        ->and($ops->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $ops->id)->count())->toBe(0)
        ->and(AuditLog::where('action', 'admin.team_removed')->exists())->toBeTrue();
    $this->actingAs($ops)->get('/admin/lots')->assertForbidden();
});

it('limits sensitive actions inside shared screens to the right role', function () {
    $owner = User::factory()->staff()->create();
    $lot = app(CreateLot::class)->run($owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $lot->forceFill(['submitted_at' => now()])->save();

    $this->actingAs(User::factory()->adminAs(AdminRole::Support)->create());
    Livewire::test(ListLots::class)->assertTableActionHidden('approve', $lot)->assertTableActionVisible('impersonate', $lot)->assertActionHidden('onboard');

    $this->actingAs(User::factory()->adminAs(AdminRole::Operations)->create());
    Livewire::test(ListLots::class)->assertTableActionVisible('approve', $lot)->assertTableActionHidden('impersonate', $lot)->assertActionVisible('onboard');

    // "Log in as" is refused on the server too.
    expect(fn () => app(Impersonation::class)->start(User::factory()->adminAs(AdminRole::Operations)->create(), $owner))->toThrow(ValidationException::class);
});

it('notifies only the admins who look after that work', function () {
    $support = User::factory()->adminAs(AdminRole::Support)->create();
    $finance = User::factory()->adminAs(AdminRole::Finance)->create();

    expect(User::query()->adminsFor(AdminArea::Support)->pluck('id')->all())->toContain($support->id, $this->owner->id)->not->toContain($finance->id);
});

it('shows the platform figures (sales, MRR, churn) to owners and finance only', function () {
    foreach ([AdminRole::Owner, AdminRole::Finance] as $role) {
        $this->flushSession();
        $this->actingAs(User::factory()->adminAs($role)->create())->get('/admin')->assertOk()->assertSee('MRR')->assertSee('Review queue');
    }
    foreach ([AdminRole::Operations, AdminRole::Support, AdminRole::Viewer] as $role) {
        $this->flushSession();
        $this->actingAs(User::factory()->adminAs($role)->create())->get('/admin')->assertOk()->assertDontSee('MRR')->assertDontSee('Platform, last 30 days')->assertSee('Review queue');
    }
});
