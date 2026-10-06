<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminRole;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Actions\InviteStaff;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Support\CurrentLot;
use App\Domain\Marketplace\Models\SavedSearch;
use App\Domain\Marketplace\Notifications\SavedSearchMatch;
use Laravel\Horizon\HorizonServiceProvider;

/* Horizon runs the Redis queue in production; with the database queue (Laragon, tests) it isn't loaded. */

it('has no Horizon dashboard when the queue is not on Redis', function () {
    expect(config('queue.default'))->not->toBe('redis');

    $this->get('/horizon')->assertNotFound();
});

it('lets only admins open Horizon, even when the app runs locally', function () {
    $this->app->register(HorizonServiceProvider::class);
    $this->app->register(App\Providers\HorizonServiceProvider::class);
    app('router')->getRoutes()->refreshNameLookups();
    $this->app['env'] = 'local'; // Horizon's own default would let anyone in here
    config(['lotlink.admin_2fa' => false]);

    $this->get('/horizon')->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
    $this->actingAs(User::factory()->adminAs(AdminRole::Support)->create())->get('/horizon')->assertForbidden(); // owners only
    $this->actingAs(User::factory()->adminAs(AdminRole::Owner)->create())->get('/horizon')->assertOk();
});

it('gives slow jobs time to finish before a queue hands them to another worker', function () {
    $longest = max(array_column(config('horizon.defaults'), 'timeout'));

    expect($longest)->toBeGreaterThanOrEqual(900) // broadcasts run up to 900 s
        ->and(config('queue.connections.redis.retry_after'))->toBeGreaterThan($longest)
        ->and(config('queue.connections.database.retry_after'))->toBeGreaterThan($longest);
});

it('drops a queued message whose record was deleted instead of failing it (a re-sent staff invite)', function () {
    config(['queue.default' => 'database']);
    $lot = Lot::factory()->create();

    app(InviteStaff::class)->run($lot, $lot->owner, 'sales@prime.ng', LotRole::Sales);
    app(InviteStaff::class)->run($lot, $lot->owner, 'sales@prime.ng', LotRole::Sales); // replaces the first

    expect(DB::table('jobs')->count())->toBe(2);
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => config('lotlink.queue_names'), '--stop-when-empty' => true, '--tries' => 3, '--memory' => 2048]);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(1); // only the open invitation is emailed
});

it('skips a saved-search alert when the buyer deleted the search while it was queued', function () {
    $buyer = User::factory()->create(['email' => 'buyer@example.com']);
    $search = SavedSearch::create(['user_id' => $buyer->id, 'name' => 'Camry under 10m', 'filters' => [], 'channel' => 'mail']);
    $alert = new SavedSearchMatch($search, Vehicle::factory()->available()->create());

    expect($alert->shouldSend($buyer, 'mail'))->toBeTrue();
    $search->delete();
    expect($alert->shouldSend($buyer, 'mail'))->toBeFalse();
});

it('runs jobs in tests the way a worker does: without the request\'s current seller', function () {
    $lot = Lot::factory()->create();
    app(CurrentLot::class)->set($lot);

    dispatch(function () {
        cache()->put('job-saw-seller', app(CurrentLot::class)->has());
    });

    expect(cache('job-saw-seller'))->toBeFalse()
        ->and(app(CurrentLot::class)->id())->toBe($lot->id);
});
