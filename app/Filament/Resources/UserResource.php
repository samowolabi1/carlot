<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Admin\Impersonation;
use App\Filament\Resources\UserResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $recordRouteKeyName = 'ulid';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->maxLength(80),
            Forms\Components\TextInput::make('phone')->disabled(),
            Forms\Components\TextInput::make('email')->email()->maxLength(255),
            Forms\Components\Select::make('role')
                ->options(collect(UserRole::cases())->mapWithKeys(fn ($r) => [$r->value => ucfirst($r->value)]))
                ->required(),
            // Registered independent inspectors sign reports that show "Independently inspected" (TDD M14).
            Forms\Components\Section::make('Independent inspector')->columns(2)->schema([
                Forms\Components\Toggle::make('is_inspector')->label('Registered inspector')
                    ->afterStateHydrated(fn (Forms\Components\Toggle $c, ?User $record) => $c->state($record?->isInspector() ?? false))
                    ->live(),
                Forms\Components\TextInput::make('inspector_company')->label('Company')->maxLength(120)
                    ->visible(fn (Forms\Get $get) => (bool) $get('is_inspector')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('phone')->searchable(),
                Tables\Columns\TextColumn::make('email')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('role')->badge()->formatStateUsing(fn (UserRole $state) => ucfirst($state->value)),
                Tables\Columns\IconColumn::make('inspector_since')->label('Inspector')->boolean()->getStateUsing(fn (User $u) => $u->isInspector())->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('Joined')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')->options(collect(UserRole::cases())->mapWithKeys(fn ($r) => [$r->value => ucfirst($r->value)])),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('impersonate')->label('Log in as')->icon('heroicon-o-arrow-right-end-on-rectangle')->color('gray')
                    ->visible(fn (User $u) => ! $u->isAdmin())
                    ->requiresConfirmation()->modalDescription('You will see LotLink as this user. Everything you do is recorded as you, in the audit log.')
                    ->action(function (User $u) {
                        /** @var User $admin */
                        $admin = Auth::user();
                        app(Impersonation::class)->start($admin, $u);

                        return redirect($u->lots()->exists() ? route('dealer.home') : route('home'));
                    }),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
