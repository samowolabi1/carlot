<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\Models\User;
use App\Domain\Billing\Actions\ChangePlanPrice;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Lots\Models\Plan;
use App\Domain\Support\Money;
use App\Filament\Resources\PlanResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
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
            // Changed only with "Change price" on the list, which also updates Paystack and tells subscribers.
            Forms\Components\TextInput::make('price')->label('Price a month (₦)')->disabled()->dehydrated(false)
                ->formatStateUsing(fn (?int $state) => $state !== null ? number_format(intdiv($state, 100)) : '0')
                ->helperText('Use "Change price" on the plans list: it updates Paystack and lets you choose who pays the new price.'),
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
                Tables\Columns\TextColumn::make('paying')->label('Paying lots')
                    ->state(fn (Plan $record) => Subscription::withoutGlobalScopes()->where('plan_id', $record->id)->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])->count()),
                Tables\Columns\TextColumn::make('listing_limit')->placeholder('Unlimited'),
                Tables\Columns\TextColumn::make('staff_limit')->placeholder('Unlimited'),
                Tables\Columns\TextColumn::make('provider_plan_code')->label('Paystack')->placeholder('—'),
                Tables\Columns\IconColumn::make('self_serve')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('price')->label('Change price')->icon('heroicon-o-banknotes')->color('gray')
                    ->visible(fn (Plan $record) => ! $record->isFree())
                    ->modalHeading(fn (Plan $record) => "Change the {$record->name} price")
                    ->modalDescription(fn (Plan $record) => 'Now '.Money::format($record->price).' a month.'.($record->provider_plan_code ? ' Paystack is updated too, so cards renew at the right amount.' : ''))
                    ->fillForm(fn (Plan $record) => ['price' => intdiv($record->price, 100), 'who' => 'new'])
                    ->form([
                        Forms\Components\TextInput::make('price')->label('New price a month')->prefix('₦')->numeric()->integer()->minValue(100)->maxValue(100_000_000)->required(),
                        Forms\Components\Radio::make('who')->label('Who pays the new price?')->required()
                            ->options([
                                'new' => 'Only lots that subscribe from now on (current subscribers keep their price)',
                                'all' => 'Everyone, from their next renewal (current subscribers are told)',
                            ]),
                    ])
                    ->action(function (Plan $record, array $data, ChangePlanPrice $change): void {
                        /** @var User $admin */
                        $admin = Auth::user();
                        try {
                            $told = $change->run($record, (int) $data['price'], $data['who'] === 'all', $admin);
                        } catch (ValidationException $e) {
                            Notification::make()->title('Price not changed')->body(collect($e->errors())->flatten()->first())->danger()->persistent()->send();

                            return;
                        }
                        Notification::make()->title("{$record->name} is now ".Money::format($record->refresh()->price).' a month')
                            ->body($data['who'] === 'all' ? "{$told} paying ".str('lot')->plural($told).' told about the change.' : 'Current subscribers keep their price.')
                            ->success()->send();
                    }),
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
