<?php

namespace App\Filament\Resources;

use App\Domain\Admin\AdminArea;
use App\Domain\Admin\AdminCounters;
use App\Domain\Inventory\Enums\BodyType;
use App\Domain\Inventory\Models\VehicleModel;
use App\Filament\Resources\Concerns\AdminsOnly;
use App\Filament\Resources\VehicleModelResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Seeded models plus the ones dealers typed in. Those arrive unapproved: check the name,
 * fix typos, then approve.
 */
class VehicleModelResource extends Resource
{
    use AdminsOnly;

    public static function adminAreas(): array
    {
        return [AdminArea::Catalogue];
    }

    protected static ?string $model = VehicleModel::class;

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'model';

    public static function getNavigationBadge(): ?string
    {
        return AdminCounters::badge('models');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('make_id')->relationship('make', 'name')->required()->searchable()->preload(),
            Forms\Components\TextInput::make('name')->required()->maxLength(60),
            Forms\Components\TextInput::make('slug')->required()->maxLength(60),
            Forms\Components\Select::make('body_type')->options(collect(BodyType::cases())->mapWithKeys(fn (BodyType $b) => [$b->value => $b->label()])),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('make'))
            ->defaultSort('approved_at')
            ->columns([
                Tables\Columns\TextColumn::make('make.name')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('body_type')->formatStateUsing(fn (?BodyType $state) => $state?->label())->placeholder('—'),
                Tables\Columns\IconColumn::make('approved_at')->label('Approved')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('approved_at')->label('Approved')->nullable(),
                Tables\Filters\SelectFilter::make('make')->relationship('make', 'name')->searchable()->preload(),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (VehicleModel $record) => $record->approved_at === null)
                    ->action(fn (VehicleModel $record) => $record->update(['approved_at' => now()])),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicleModels::route('/'),
            'edit' => Pages\EditVehicleModel::route('/{record}/edit'),
        ];
    }
}
