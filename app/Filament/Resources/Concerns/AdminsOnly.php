<?php

namespace App\Filament\Resources\Concerns;

use App\Domain\Accounts\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The admin panel authorises by role, not by the dealer-side model policies. `LotPolicy::view` means
 * "member of this lot", so letting Filament use it hid "View" and gave admins 403 on every lot, and ran
 * a membership query per row. Only admins reach /admin (`User::canAccessPanel`); each resource still
 * narrows what's possible with its own canCreate()/actions.
 * Also makes record links match the column the resource looks records up by (see getUrl()).
 */
trait AdminsOnly
{
    public static function can(string $action, ?Model $record = null): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->isAdmin();
    }

    /**
     * Links to a record use the same column the resource looks records up by. Filament hands the model
     * to route(), which uses the model's own key (users: id), so "Edit" on a user went to a 404.
     *
     * @param  array<mixed>  $parameters
     */
    public static function getUrl(string $name = 'index', array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null): string
    {
        $key = static::getRecordRouteKeyName();
        if ($key !== null && ($parameters['record'] ?? null) instanceof Model) {
            $parameters['record'] = $parameters['record']->getAttribute($key);
        }

        return parent::getUrl($name, $parameters, $isAbsolute, $panel, $tenant);
    }
}
