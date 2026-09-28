<?php

namespace App\Filament\Resources;

use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Lots\Models\Plan;
use App\Domain\Support\Money;
use App\Filament\Resources\PlanResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Throwable;

/** Plans and prices (TDD M17). Prices are typed in naira and stored in kobo. */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Billing';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(40),
            Forms\Components\TextInput::make('price')->label('Price a month (₦)')->numeric()->minValue(0)->required()
                ->formatStateUsing(fn (?int $state) => $state !== null ? intdiv($state, 100) : 0)
                ->dehydrateStateUsing(fn ($state) => Money::fromMajor((int) $state)),
            Forms\Components\TextInput::make('listing_limit')->numeric()->minValue(1)->helperText('Empty for unlimited'),
            Forms\Components\TextInput::make('staff_limit')->numeric()->minValue(1)->helperText('Empty for unlimited'),
            Forms\Components\TextInput::make('free_spotlights')->numeric()->minValue(0)->required()->label('Free car spotlights a month'),
            Forms\Components\Toggle::make('self_serve')->label('Owners can buy it in the app')->helperText('Off shows "Talk to us"'),
            Forms\Components\TextInput::make('provider_plan_code')->label('Paystack plan code')->placeholder('PLN_...')
                ->helperText('Makes the plan renew monthly. Use "Create on Paystack" on the list to make one.'),
            Forms\Components\KeyValue::make('features')->helperText('e.g. open_orders = 10, share_cards = 0/1'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('price')->formatStateUsing(fn (int $state) => Money::format($state)),
                Tables\Columns\TextColumn::make('listing_limit')->placeholder('Unlimited'),
                Tables\Columns\TextColumn::make('staff_limit')->placeholder('Unlimited'),
                Tables\Columns\TextColumn::make('provider_plan_code')->label('Paystack')->placeholder('—'),
                Tables\Columns\IconColumn::make('self_serve')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('paystack')->label('Create on Paystack')->icon('heroicon-o-arrow-up-tray')
                    ->visible(fn (Plan $plan) => $plan->provider_plan_code === null && $plan->price > 0 && $plan->self_serve)
                    ->requiresConfirmation()
                    ->action(function (Plan $plan, PaymentGateway $gateway): void {
                        try {
                            $plan->update(['provider_plan_code' => $gateway->createPlan("LotLink {$plan->name}", $plan->price, $plan->interval)]);
                            Notification::make()->title('Plan created on Paystack')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlans::route('/'),
            'edit' => Pages\EditPlan::route('/{record}/edit'),
        ];
    }
}
