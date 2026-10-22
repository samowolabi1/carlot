<?php

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Admin\Actions\EnsureFirstAdmin;
use App\Domain\Admin\AdminRole;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

it('never creates a guessable admin on a real server', function () {
    $this->app['env'] = 'production';

    config(['lotlink.first_admin' => ['email' => null, 'password' => null, 'phone' => null]]);
    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
    config(['lotlink.first_admin' => ['email' => 'boss@caryardng.com', 'password' => 'short', 'phone' => null]]);
    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

    expect(User::where('role', UserRole::Admin)->exists())->toBeFalse();
});

it('creates the first admin from the settings and keeps their password when seeded again', function () {
    $this->app['env'] = 'production';
    config(['lotlink.first_admin' => ['email' => ' Boss@CarYardNG.com ', 'password' => 'a-long-first-password', 'phone' => '']]);
    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

    $admin = User::where('email', 'boss@caryardng.com')->sole();
    expect($admin)->role->toBe(UserRole::Admin)->admin_role->toBe(AdminRole::Owner)->phone->toBeNull()
        ->and(Hash::check('a-long-first-password', $admin->password))->toBeTrue();

    $admin->update(['password' => 'changed-after-signing-in']);
    config(['lotlink.first_admin.password' => 'a-long-first-password']);
    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

    expect(Hash::check('changed-after-signing-in', $admin->fresh()->password))->toBeTrue()
        ->and(User::where('role', UserRole::Admin)->count())->toBe(1);
});

/* Without a terminal, the cron job applies a saved .env: EnsureFirstAdmin creates or corrects the admin. */

afterEach(function () {
    @unlink(storage_path('app/first-admin-password'));
    @unlink(storage_path('app/reset-admin-password'));
});

it('takes a corrected ADMIN_PASSWORD from .env until the admin changes it in the app', function () {
    $this->app['env'] = 'production';
    config(['lotlink.first_admin' => ['email' => 'boss@caryardng.com', 'password' => 'first-typed-password', 'phone' => null]]);
    expect(app(EnsureFirstAdmin::class)->run(fromEnvChange: true)['status'])->toBe('created');

    config(['lotlink.first_admin.password' => 'corrected-password-2026']);
    expect(app(EnsureFirstAdmin::class)->run(fromEnvChange: true)['status'])->toBe('updated');
    $admin = User::where('email', 'boss@caryardng.com')->sole();
    expect(Hash::check('corrected-password-2026', $admin->password))->toBeTrue();

    // Changed in the app: .env never overrides it again (and seeding never changes it either).
    $admin->update(['password' => 'chosen-in-the-app-2026']);
    config(['lotlink.first_admin.password' => 'another-env-password']);
    expect(app(EnsureFirstAdmin::class)->run(fromEnvChange: true)['status'])->toBe('kept')
        ->and(Hash::check('chosen-in-the-app-2026', $admin->fresh()->password))->toBeTrue();
});

it('resets the admin password and two-step sign-in when asked with a file', function () {
    $this->app['env'] = 'production';
    $admin = User::factory()->create(['email' => 'boss@caryardng.com', 'role' => UserRole::Admin, 'admin_role' => AdminRole::Owner, 'password' => 'forgotten-password-1']);
    $admin->forceFill(['two_factor_secret' => 'SECRET', 'two_factor_confirmed_at' => now()])->save();
    config(['lotlink.first_admin' => ['email' => 'boss@caryardng.com', 'password' => 'fresh-password-2026', 'phone' => null]]);

    expect(app(EnsureFirstAdmin::class)->run(fromEnvChange: true)['status'])->toBe('kept');

    touch(storage_path('app/reset-admin-password'));
    expect(app(EnsureFirstAdmin::class)->run()['status'])->toBe('reset');
    $admin->refresh();
    expect(Hash::check('fresh-password-2026', $admin->password))->toBeTrue()
        ->and($admin->two_factor_confirmed_at)->toBeNull()
        ->and($admin->two_factor_secret)->toBeNull()
        ->and(is_file(storage_path('app/reset-admin-password')))->toBeFalse();
});

it('explains a password cut short by an unquoted #', function () {
    $env = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($env, "APP_NAME=CarYard\nADMIN_PASSWORD=Lagos#2026Cars!\n");
    expect(EnsureFirstAdmin::why('boss@caryardng.com', 'Lagos', $env))->toContain('it has 5')->toContain('double quotes');

    file_put_contents($env, "ADMIN_PASSWORD=\"Lagos#2026Cars!\"\n");
    expect(EnsureFirstAdmin::why('boss@caryardng.com', 'short', $env))->not->toContain('double quotes');
    unlink($env);
});
