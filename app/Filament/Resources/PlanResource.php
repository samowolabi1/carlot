<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminArea;
use App\Domain\Billing\Actions\ChangePlanPrice;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Gateways\PaymentGateways;
use App\Domain\Lots\Models\Plan;
use App\Domain\Support\Money;
use App\Filament\Resources\Concerns\AdminsOnly;
use App\Filament\Resources\PlanResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Plans and prices (TDD M17). Prices are typed in naira and stored in kobo. */
class PlanResource extends Resource
{
    use AdminsOnly;

    public static function adminAreas(): array
    {
        return [AdminArea::Billing];
    }

    protected static ?string $model = Plan::class;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(40),
            // Changed only with "Change price" on the list, which also updates the providers and tells subscribers.
            Forms\Components\TextInput::make('price')->label('Price a month (₦)')->disabled()->dehydrated(false)
                ->formatStateUsing(fn (?int $state) => $state !== null ? number_format(intdiv($state, 100)) : '0')
                ->helperText('Use "Change price" on the plans list: it updates Paystack and Flutterwave and lets you choose who pays the new price.'),
            Forms\Components\TextInput::make('listing_limit')->numeric()->integer()->minValue(1)->maxValue(100_000)->helperText('Empty for unlimited'),
            Forms\Components\TextInput::make('staff_limit')->numeric()->integer()->minValue(1)->maxValue(1_000)->helperText('Empty for unlimited'),
            Forms\Components\TextInput::make('free_spotlights')->numeric()->integer()->minValue(0)->maxValue(100)->required()->label('Free car spotlights a month'),
            Forms\Components\Toggle::make('self_serve')->label('Owners can buy it in the app')->helperText('Off shows "Talk to us"'),
            Forms\Components\TextInput::make('provider_plan_code')->label('Paystack plan code')->maxLength(64)->alphaDash()->placeholder('PLN_...')
                ->helperText('Makes the plan renew monthly with Paystack. Use "Create on Paystack" on the list to make one.'),
            Forms\Components\TextInput::make('flutterwave_plan_id')->label('Flutterwave payment plan id')->maxLength(20)->regex('/^\d+$/')->placeholder('123456')
                ->helperText('Makes the plan renew monthly with Flutterwave. Use "Create on Flutterwave" on the list.'),
            Forms\Components\KeyValue::make('features')->helperText('e.g. open_orders = 10, share_cards = 0/1'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['subscriptions as paying_count' => fn (Builder $q) => $q->withoutGlobalScopes()->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])]))
            ->defaultSort('sort')
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('price')->formatStateUsing(fn (int $state) => Money::format($state)),
                Tables\Columns\TextColumn::make('paying')->label('Paying lots')
                    // Counted with the table's query (withCount); a plan loaded some other way counts on its own.
                    ->state(fn (Plan $record) => (int) ($record->getAttribute('paying_count') ?? $record->subscriptions()->withoutGlobalScopes()->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])->count())),
                Tables\Columns\TextColumn::make('listing_limit')->placeholder('Unlimited'),
                Tables\Columns\TextColumn::make('staff_limit')->placeholder('Unlimited'),
                Tables\Columns\TextColumn::make('provider_plan_code')->label('Paystack')->placeholder('—'),
                Tables\Columns\TextColumn::make('flutterwave_plan_id')->label('Flutterwave')->placeholder('—'),
                Tables\Columns\IconColumn::make('self_serve')->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('price')->label('Change price')->icon('heroicon-o-banknotes')->color('gray')
                    ->visible(fn (Plan $record) => ! $record->isFree())
                    ->modalHeading(fn (Plan $record) => "Change the {$record->name} price")
                    ->modalDescription(fn (Plan $record) => 'Now '.Money::format($record->price).' a month.'.($record->provider_plan_code || $record->flutterwave_plan_id ? ' The payment providers are updated too, so cards renew at the right amount (Flutterwave only for new subscribers).' : ''))
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
                ...collect(PaymentGateways::PROVIDERS)->map(fn (string $label, string $provider) => Tables\Actions\Action::make("create_{$provider}")
                    ->label("Create on {$label}")->icon('heroicon-o-arrow-up-tray')->color('gray')
                    ->visible(fn (Plan $plan) => $plan->codeFor($provider) === null && $plan->price > 0 && $plan->self_serve && PaymentGateways::configured($provider))
                    ->requiresConfirmation()
                    ->action(function (Plan $plan, PaymentGateways $gateways) use ($label, $provider): void {
                        try {
                            $plan->setCodeFor($provider, $gateways->for($provider)->createPlan("LotLink {$plan->name}", $plan->price, $plan->interval));
                            Notification::make()->title("Plan created on {$label}")->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }))->values()->all(),
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
