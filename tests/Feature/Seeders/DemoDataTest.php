<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Domain\System\Actions\LoadDemoData;
use Database\Seeders\DemoMarketplaceSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/* Demo data on a server without a terminal (storage/app/load-demo-data → LoadDemoData). */

it('refuses the demo seeder on a real server unless LoadDemoData asks', function () {
    $this->app['env'] = 'production';

    expect(fn () => (new DemoMarketplaceSeeder)->run())->toThrow(RuntimeException::class);
});

it('loads the demo on a server and gives the demo accounts DEMO_PASSWORD', function () {
    $this->app['env'] = 'production';
    Storage::fake(config('lotlink.media_disk'));
    Storage::fake('local');
    config(['lotlink.demo_password' => 'demo-password-2026', 'lotlink.share_cards' => false]);
    $queue = config('queue.default');

    $lines = app(LoadDemoData::class)->run();

    expect(Lot::withoutGlobalScopes()->where('name', 'Ade Motors')->exists())->toBeTrue()
        ->and(Vehicle::withoutGlobalScopes()->count())->toBe(16)
        ->and(Hash::check('demo-password-2026', User::where('phone', '+2348000000101')->sole()->password))->toBeTrue()
        ->and(Hash::check('demo-password-2026', User::where('email', 'lender@lotlink.test')->sole()->password))->toBeTrue()
        ->and(implode("\n", $lines))->toContain('0800 000 0101')->not->toContain('demo-password-2026')
        ->and(config('queue.default'))->toBe($queue)
        ->and(config('lotlink.demo_allowed'))->toBeFalse();
});
