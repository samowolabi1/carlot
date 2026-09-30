<?php

namespace App\Filament\Resources\LenderResource\Pages;

use App\Domain\Accounts\Models\User;
use App\Domain\Finance\Actions\OnboardLender;
use App\Filament\Resources\LenderResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ListLenders extends ListRecords
{
    protected static string $resource = LenderResource::class;

    protected ?string $subheading = 'Banks and finance companies that give buyers car loans. New sign-ups wait here for approval; you can also onboard a partner directly.';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('onboard')->label('Onboard a lender')->icon('heroicon-o-plus-circle')
                ->modalHeading('Onboard a lender')
                ->modalDescription('Creates the lender (active straight away) and its first admin\'s account, and emails them how to sign in to the lender portal.')
                ->modalWidth('3xl')
                ->form(LenderResource::detailsSchema(onboarding: true))
                ->action(function (array $data, OnboardLender $onboard): void {
                    /** @var User $admin */
                    $admin = Auth::user();
                    try {
                        $lender = $onboard->run($admin, $data);
                        Notification::make()->title("{$lender->name} is set up")->body('We emailed their admin how to sign in.')->success()->send();
                    } catch (ValidationException $e) {
                        Notification::make()->title(collect($e->errors())->flatten()->first() ?? 'Check the details')->danger()->send();
                    }
                }),
        ];
    }
}
