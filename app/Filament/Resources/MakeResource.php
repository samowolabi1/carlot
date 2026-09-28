<?php

namespace App\Filament\Resources;

use App\Domain\Inventory\Models\Make;
use App\Filament\Resources\MakeResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class MakeResource extends Resource
{
    protected static ?string $model = Make::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Catalogue';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required()->maxLength(60)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, ?string $state, string $operation) => $operation === 'create' ? $set('slug', Str::slug((string) $state)) : null),
            Forms\Components\TextInput::make('slug')->required()->maxLength(60)->unique(ignoreRecord: true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('models_count')->counts('models')->label('Models'),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMakes::route('/'),
            'create' => Pages\CreateMake::route('/create'),
            'edit' => Pages\EditMake::route('/{record}/edit'),
        ];
    }
}
