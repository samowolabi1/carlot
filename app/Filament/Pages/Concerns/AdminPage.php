<?php

namespace App\Filament\Pages\Concerns;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminArea;
use Illuminate\Support\Facades\Auth;

/** Admin pages (settings, system health) open only to roles whose areas include the page's (see AdminsOnly for resources). */
trait AdminPage
{
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->adminCan(...static::adminAreas());
    }

    /** @return list<AdminArea> */
    public static function adminAreas(): array
    {
        return [AdminArea::Settings];
    }
}
