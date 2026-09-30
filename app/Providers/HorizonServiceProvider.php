<?php

namespace App\Providers;

use App\Domain\Accounts\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Horizon: the Redis queue workers and their dashboard at /horizon. Only registered when the queue runs on
 * Redis (AppServiceProvider), so Laragon and other database-queue setups never load it.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /** Platform admins only, after the same two-factor check as the admin panel (config/horizon.php middleware). */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user?->isAdmin() ?? false);
    }

    /** Always the gate. Horizon's default also lets everyone in when APP_ENV=local, which a Laragon box running Redis would be. */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn (Request $request): bool => Gate::check('viewHorizon', [$request->user()]));
    }
}
