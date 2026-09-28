<?php

namespace App\Filament\Resources;

use App\Domain\Billing\Models\Coupon;
use App\Filament\Resources\CouponResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Trial codes, e.g. the launch offer of 3 months on Starter (TDD M16). */
class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'Billing';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')->required()->maxLength(32)->unique(ignoreRecord: true)->placeholder('LAUNCH3'),
            Forms\Components\Select::make('plan_id')->relationship('plan', 'name')->required(),
            Forms\Components\TextInput::make('trial_days')->numeric()->minValue(1)->maxValue(365)->required()->default(90),
            Forms\Components\TextInput::make('max_redemptions')->numeric()->minValue(1)->helperText('Empty for no limit'),
            Forms\Components\DateTimePicker::make('expires_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable(),
                Tables\Columns\TextColumn::make('plan.name'),
                Tables\Columns\TextColumn::make('trial_days')->suffix(' days'),
                Tables\Columns\TextColumn::make('redeemed')->formatStateUsing(fn (Coupon $c) => $c->redeemed.($c->max_redemptions ? " of {$c->max_redemptions}" : '')),
                Tables\Columns\TextColumn::make('expires_at')->date()->placeholder('Never'),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCoupons::route('/'),
            'create' => Pages\CreateCoupon::route('/create'),
            'edit' => Pages\EditCoupon::route('/{record}/edit'),
        ];
    }
}
