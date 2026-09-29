<?php

namespace App\Filament\Resources\LotResource\Pages;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\Impersonation;
use App\Domain\Lots\Models\Lot;
use App\Filament\Resources\LotResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

/** One lot: its details, with the support actions at the top (open the public page, log in as the owner). */
class ViewLot extends ViewRecord
{
    protected static string $resource = LotResource::class;

    protected function getHeaderActions(): array
    {
        /** @var Lot $lot */
        $lot = $this->record;

        return [
            Action::make('public')->label('Public page')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                ->url(route('lots.show', $lot), shouldOpenInNewTab: true),
            Action::make('impersonate')->label('Log in as owner')->icon('heroicon-o-arrow-right-end-on-rectangle')
                ->visible($lot->owner !== null && ! $lot->owner->isAdmin())
                ->requiresConfirmation()
                ->modalDescription('You will see LotLink as the lot owner. Everything you do there is logged with your name as well as theirs. Use "Back to admin" at the top, or Sign out, to return.')
                ->action(function () use ($lot) {
                    /** @var User $admin */
                    $admin = Auth::user();
                    app(Impersonation::class)->start($admin, $lot->owner);

                    return redirect()->route('dealer.dashboard', $lot);
                }),
        ];
    }
}
