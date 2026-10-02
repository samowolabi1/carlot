<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminArea;
use App\Domain\Admin\Impersonation;
use App\Filament\Resources\Concerns\AdminsOnly;
use App\Filament\Resources\UserResource\Pages;
use App\Rules\FieldPattern;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class UserResource extends Resource
{
    use AdminsOnly;

    public static function adminAreas(): array
    {
        return [AdminArea::Support];
    }

    // Global search (Ctrl/⌘ K): a person by name, email or phone.
    protected static ?string $recordTitleAttribute = 'name';

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email', 'phone'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var User $record */
        return array_filter(['Phone' => (string) $record->phone, 'Email' => (string) $record->email, 'Role' => ucfirst($record->role->value)]);
    }

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Marketplace';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordRouteKeyName = 'ulid';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->maxLength(80)->rules(['nullable', new FieldPattern('person_name')]),
            Forms\Components\TextInput::make('phone')->disabled(),
            Forms\Components\TextInput::make('email')->email()->rule('email:rfc,strict')->maxLength(190),
            // Admins are managed in System → Admin team (invites, roles, removal), never here.
            Forms\Components\Select::make('role')
                ->options([UserRole::Customer->value => 'Customer', UserRole::Staff->value => 'Staff'])
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
                Tables\Actions\EditAction::make()->hidden(fn (User $u) => $u->isAdmin()),
                Tables\Actions\Action::make('impersonate')->label('Log in as')->icon('heroicon-o-arrow-right-end-on-rectangle')->color('gray')
                    ->visible(fn (User $u) => ! $u->isAdmin())
                    ->requiresConfirmation()->modalDescription('You will see LotLink as this user. Everything you do there is logged with your name as well as theirs. Use "Back to admin" at the top, or Sign out, to return.')
                    ->action(function (User $u) {
                        /** @var User $admin */
                        $admin = Auth::user();
                        app(Impersonation::class)->start($admin, $u);

                        return redirect($u->lots()->exists() ? route('dealer.home') : route('home'));
                    }),
            ]);
    }

    /** Admins are edited in the Admin team page (owners only). */
    public static function canEdit(Model $record): bool
    {
        return static::can('update', $record) && ! ($record instanceof User && $record->isAdmin());
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
