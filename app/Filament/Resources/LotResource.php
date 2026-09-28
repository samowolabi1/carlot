<?php

namespace App\Filament\Resources;

use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Filament\Resources\LotResource\Pages;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LotResource extends Resource
{
    protected static ?string $model = Lot::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $recordRouteKeyName = 'ulid';

    public static function getNavigationBadge(): ?string
    {
        $pending = Lot::where('status', LotStatus::Pending)->whereNotNull('submitted_at')->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('owner'))
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->description(fn (Lot $lot) => $lot->slug),
                Tables\Columns\TextColumn::make('owner.name')->label('Owner')->description(fn (Lot $lot) => $lot->owner?->phone),
                Tables\Columns\TextColumn::make('city')->description(fn (Lot $lot) => $lot->state)->searchable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (LotStatus $state) => $state->label())
                    ->color(fn (LotStatus $state) => match ($state) {
                        LotStatus::Pending => 'warning',
                        LotStatus::Active => 'success',
                        LotStatus::Suspended => 'danger',
                    }),
                Tables\Columns\TextColumn::make('submitted_at')->since()->placeholder('Still onboarding')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(collect(LotStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                Tables\Filters\TernaryFilter::make('submitted_at')->label('Submitted')->nullable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (Lot $lot) => $lot->status !== LotStatus::Active)
                    ->requiresConfirmation()
                    ->action(fn (Lot $lot) => $lot->update(['status' => LotStatus::Active])),
                Tables\Actions\Action::make('suspend')
                    ->icon('heroicon-o-no-symbol')->color('danger')
                    ->visible(fn (Lot $lot) => $lot->status === LotStatus::Active)
                    ->requiresConfirmation()
                    ->action(fn (Lot $lot) => $lot->update(['status' => LotStatus::Suspended])),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Business')->columns(3)->schema([
                TextEntry::make('name'),
                TextEntry::make('owner.name')->label('Owner'),
                TextEntry::make('status')->formatStateUsing(fn (LotStatus $state) => $state->label())->badge(),
                TextEntry::make('phone'),
                TextEntry::make('whatsapp'),
                TextEntry::make('email')->placeholder('—'),
                TextEntry::make('tagline')->placeholder('—')->columnSpanFull(),
                TextEntry::make('about')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Location')->columns(3)->schema([
                TextEntry::make('address')->placeholder('Not set'),
                TextEntry::make('city')->placeholder('—'),
                TextEntry::make('state')->placeholder('—'),
                TextEntry::make('landmark')->placeholder('—'),
                TextEntry::make('coordinates')
                    ->state(fn (Lot $lot) => $lot->hasLocation() ? "{$lot->latitude}, {$lot->longitude}" : null)
                    ->url(fn (Lot $lot) => $lot->directionsUrl(), shouldOpenInNewTab: true)
                    ->placeholder('No pin yet'),
            ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLots::route('/'),
            'view' => Pages\ViewLot::route('/{record}'),
        ];
    }
}
