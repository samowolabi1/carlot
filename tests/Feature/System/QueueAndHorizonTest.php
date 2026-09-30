<?php

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
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
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/horizon')->assertOk();
});

it('gives slow jobs time to finish before a queue hands them to another worker', function () {
    $longest = max(array_column(config('horizon.defaults'), 'timeout'));

    expect($longest)->toBeGreaterThanOrEqual(900) // broadcasts run up to 900 s
        ->and(config('queue.connections.redis.retry_after'))->toBeGreaterThan($longest)
        ->and(config('queue.connections.database.retry_after'))->toBeGreaterThan($longest);
});
