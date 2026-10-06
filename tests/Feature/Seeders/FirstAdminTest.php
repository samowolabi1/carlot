<?php

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
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
