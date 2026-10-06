<?php

namespace Database\Seeders;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminRole;
use App\Domain\Messaging\MessageCatalogue;
use App\Domain\Support\PhoneNumber;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([PlanSeeder::class, CouponSeeder::class, VehicleCatalogueSeeder::class]);
        MessageCatalogue::sync();

        $this->firstAdmin();

        // On a local install (Laragon), the demo lenders too, so the lender portal can be tried straight away
        // (lender@lotlink.test / password). Never in production.
        if (app()->isLocal()) {
            $this->call(DemoLenderSeeder::class);
        }
    }

    /** Platform admin for the Filament panel at /admin, from config('lotlink.first_admin') (ADMIN_* in .env). */
    private function firstAdmin(): void
    {
        $local = app()->environment('local', 'testing');
        $email = Str::lower(trim((string) config('lotlink.first_admin.email'))) ?: ($local ? 'admin@lotlink.test' : '');
        $password = (string) config('lotlink.first_admin.password') ?: ($local ? 'password' : '');

        $admin = $email === '' ? null : User::where('email', $email)->first();
        if (! $admin) {
            // Never a guessable admin on a real server: without ADMIN_EMAIL and a strong ADMIN_PASSWORD, skip it.
            if ($email === '' || (! $local && mb_strlen($password) < 12)) {
                $this->command?->warn('No admin created: set ADMIN_EMAIL and ADMIN_PASSWORD (12+ characters) in .env, then run php artisan db:seed --force.');

                return;
            }
            $phone = PhoneNumber::tryNormalize(config('lotlink.first_admin.phone')) ?? ($local ? '+2348000000000' : null);
            $phone = $phone && User::withTrashed()->where('phone', $phone)->exists() ? null : $phone;
            $admin = User::create([
                'email' => $email,
                'name' => 'CarYard Admin',
                'phone' => $phone,
                'password' => $password,
                'role' => UserRole::Admin,
                'phone_verified_at' => $phone ? now() : null,
            ]);
            $admin->forceFill(['email_verified_at' => now()]);
        }

        // The first admin owns the admin team (invites the rest from /admin → System → Admin team). An existing
        // admin keeps their password, so seeding again never undoes a change made after signing in.
        $admin->forceFill(['role' => UserRole::Admin, 'admin_role' => $admin->admin_role ?? AdminRole::Owner])->save();
    }
}
