<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Admin avatars drawn here as initials, instead of Filament's default (ui-avatars.com), which sends every admin's
 * name to a third party and shows broken images when that site can't be reached.
 */
class InitialsAvatar implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = Str::of(Filament::getNameForDefaultAvatar($record))->trim();
        $initials = $name->explode(' ')->filter()->take(2)->map(fn (string $part) => Str::upper(Str::substr($part, 0, 1)))->implode('') ?: '?';

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#16302B"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="DM Sans, Arial, sans-serif" font-size="26" font-weight="700" fill="#FFFFFF">'
            .e($initials).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
