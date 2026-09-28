<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\Models\User;
use App\Domain\Billing\Actions\RefundPayment;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Models\Payment;
use App\Filament\Resources\PaymentResource\Pages;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Throwable;

/** What lots have paid LotLink, with refunds through Paystack (TDD M17). */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?string $recordRouteKeyName = 'ulid';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('lot'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->dateTime('j M Y, H:i')->sortable(),
                Tables\Columns\TextColumn::make('lot.name')->searchable(),
                Tables\Columns\TextColumn::make('description'),
                Tables\Columns\TextColumn::make('amount')->formatStateUsing(fn (Payment $p) => $p->money()),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (PaymentStatus $state) => $state->label())
                    ->color(fn (PaymentStatus $state) => match ($state) {
                        PaymentStatus::Success => 'success',
                        PaymentStatus::Pending => 'warning',
                        PaymentStatus::Failed => 'danger',
                        PaymentStatus::Refunded => 'gray',
                    }),
                Tables\Columns\TextColumn::make('provider'),
                Tables\Columns\TextColumn::make('reference')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(collect(PaymentStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->actions([
                Tables\Actions\Action::make('refund')->icon('heroicon-o-arrow-uturn-left')->color('danger')
                    ->visible(fn (Payment $p) => $p->status === PaymentStatus::Success)
                    ->requiresConfirmation()->modalDescription('Refunds the full amount through Paystack. A spotlight stops straight away.')
                    ->action(function (Payment $payment, RefundPayment $refund): void {
                        try {
                            /** @var User $admin */
                            $admin = Auth::user();
                            $refund->run($payment, $admin);
                            Notification::make()->title('Refunded')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPayments::route('/')];
    }
}
