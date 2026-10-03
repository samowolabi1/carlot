<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Admin\Actions\ManageAdminTeam;
use App\Domain\Admin\AdminArea;
use App\Domain\Admin\AdminRole;
use App\Filament\Resources\AdminTeamResource\Pages;
use App\Filament\Resources\Concerns\AdminsOnly;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** CarYard's own staff in /admin (owners only): invite, change role, resend the invite, remove. */
class AdminTeamResource extends Resource
{
    use AdminsOnly;

    public static function adminAreas(): array
    {
        return [AdminArea::Team];
    }

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Admin team';

    protected static ?string $modelLabel = 'admin';

    protected static ?string $pluralModelLabel = 'admin team';

    protected static ?string $slug = 'admin-team';

    protected static ?int $navigationSort = -1;

    protected static ?string $recordRouteKeyName = 'ulid';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('role', UserRole::Admin)->with('inviter');
    }

    public static function canCreate(): bool
    {
        return false; // "Invite an admin" on the list page.
    }

    /** @return array<string, string> role => "Label: what they can do" */
    public static function roleOptions(): array
    {
        return collect(AdminRole::cases())->mapWithKeys(fn (AdminRole $r) => [$r->value => $r->label()])->all();
    }

    /** @return array<string, string> */
    public static function roleDescriptions(): array
    {
        return collect(AdminRole::cases())->mapWithKeys(fn (AdminRole $r) => [$r->value => $r->description()])->all();
    }

    public static function table(Table $table): Table
    {
        $me = fn (User $u) => $u->is(Auth::user());

        return $table
            ->defaultSort('created_at')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->description(fn (User $u) => $u->email)
                    ->formatStateUsing(fn (User $u) => ($u->name ?? '—').($u->is(Auth::user()) ? ' (you)' : '')),
                Tables\Columns\TextColumn::make('admin_role')->label('Role')->badge()
                    ->state(fn (User $u) => $u->adminRole()?->label())
                    ->color(fn (User $u) => $u->adminRole() === AdminRole::Owner ? 'warning' : 'gray')
                    ->description(fn (User $u) => $u->adminRole()?->description()),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->state(fn (User $u) => $u->password === null ? 'Invited' : ($u->two_factor_confirmed_at ? 'Active' : 'Needs 2FA'))
                    ->color(fn (string $state) => match ($state) {
                        'Active' => 'success', 'Invited' => 'info', default => 'warning'
                    })
                    ->description(fn (User $u) => $u->password === null && $u->invited_at ? 'invite sent '.$u->invited_at->diffForHumans() : null),
                Tables\Columns\TextColumn::make('last_seen_at')->label('Last seen')->since()->placeholder('Never')->sortable(),
                Tables\Columns\TextColumn::make('inviter.name')->label('Invited by')->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('admin_role')->label('Role')->options(self::roleOptions()),
            ])
            ->actions([
                Tables\Actions\Action::make('role')->label('Change role')->icon('heroicon-o-arrows-right-left')
                    ->hidden($me)
                    ->fillForm(fn (User $u) => ['role' => $u->adminRole()?->value])
                    ->form([Forms\Components\Radio::make('role')->options(self::roleOptions())->descriptions(self::roleDescriptions())->required()])
                    ->action(fn (User $u, array $data) => self::team(fn (ManageAdminTeam $t, User $owner) => $t->changeRole($owner, $u, AdminRole::from($data['role'])), 'Role changed')),
                Tables\Actions\Action::make('resend')->label('Resend invite')->icon('heroicon-o-envelope')->color('gray')
                    ->visible(fn (User $u) => $u->password === null)->requiresConfirmation()
                    ->action(fn (User $u) => self::team(fn (ManageAdminTeam $t, User $owner) => $t->resend($owner, $u), 'Invite sent again')),
                Tables\Actions\Action::make('remove')->icon('heroicon-o-user-minus')->color('danger')
                    ->hidden($me)->requiresConfirmation()
                    ->modalDescription('They lose admin access straight away and are signed out everywhere. Their past actions stay in the audit log.')
                    ->action(fn (User $u) => self::team(fn (ManageAdminTeam $t, User $owner) => $t->remove($owner, $u), 'Removed from the admin team')),
            ]);
    }

    /** @param callable(ManageAdminTeam, User): mixed $step */
    public static function team(callable $step, string $done): void
    {
        try {
            /** @var User $owner */
            $owner = Auth::user();
            $step(app(ManageAdminTeam::class), $owner);
            Notification::make()->title($done)->success()->send();
        } catch (ValidationException $e) {
            Notification::make()->title((string) collect($e->errors())->flatten()->first())->danger()->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAdminTeam::route('/')];
    }
}
