<?php

namespace App\Domain\System\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Support\PhoneNumber;
use Database\Seeders\DemoMarketplaceSeeder;
use Database\Seeders\DemoTrustSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\VehicleCatalogueSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Demo data on a server without a terminal: an empty storage/app/load-demo-data makes the cron job (lotlink:auto-update)
 * run the demo seeders (4 sellers with real-photo cars, 2 lenders, reviews and an inspection). The demo accounts get
 * DEMO_PASSWORD from .env (12+ characters), else a random one, so nobody can sign in with the well-known local
 * passwords. For test/staging sites: the people and sellers are made up.
 */
final class LoadDemoData
{
    /** @return list<string> lines for the log: what was loaded and how to sign in */
    public function run(): array
    {
        // The seeders process photos straight away (sync queue); put the queue back afterwards for the rest of the cron run.
        $queue = (string) config('queue.default');
        config(['lotlink.demo_allowed' => true]);
        try {
            // Plans and the car catalogue first (idempotent): the demo cars need them.
            foreach ([PlanSeeder::class, VehicleCatalogueSeeder::class, DemoMarketplaceSeeder::class, DemoTrustSeeder::class] as $seeder) {
                Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
            }
        } finally {
            config(['lotlink.demo_allowed' => false, 'queue.default' => $queue]);
            Queue::setDefaultDriver($queue);
        }

        $chosen = (string) config('lotlink.demo_password');
        $password = mb_strlen($chosen) >= 12 ? $chosen : Str::password(16, symbols: false);

        $accounts = [];
        foreach (DemoMarketplaceSeeder::LOTS as $lot) {
            $accounts[] = [$lot['phone'], "seller, {$lot['name']}"];
        }
        $accounts[] = [DemoTrustSeeder::REVIEWS[0][0], 'buyer, '.DemoTrustSeeder::REVIEWS[0][1]];
        $accounts[] = ['+2348000000200', 'independent inspector'];
        $accounts[] = ['lender@lotlink.test', 'lender, Kobo Motor Finance portal'];

        $lines = ['Demo data loaded: 4 sellers, their cars with photos, 2 lenders, reviews and an inspection.'];
        foreach ($accounts as [$login, $who]) {
            $user = str_contains($login, '@') ? User::where('email', $login)->first() : User::where('phone', $login)->first();
            if ($user) {
                $user->forceFill(['password' => $password])->save();
                $lines[] = '  '.(str_contains($login, '@') ? $login : PhoneNumber::display($login))." ({$who})";
            }
        }
        $lines[] = mb_strlen($chosen) >= 12
            ? 'Sign in with the password at /login → "Sign in with a password": DEMO_PASSWORD from .env.'
            : "Sign in with the password at /login → \"Sign in with a password\": {$password} (set DEMO_PASSWORD in .env to choose your own).";

        return $lines;
    }
}
