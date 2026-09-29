<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Trust\Actions\ModerateListing;
use App\Filament\Resources\Concerns\AdminsOnly;
use App\Filament\Resources\VehicleResource\Pages;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** Every listing across lots (TDD M17 moderation): hide one, or put a held one back on sale. */
class VehicleResource extends Resource
{
    use AdminsOnly;

    protected static ?string $model = Vehicle::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Marketplace';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Listings';

    protected static ?string $modelLabel = 'listing';

    protected static ?string $recordRouteKeyName = 'ulid';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes()->whereNull('vehicles.deleted_at')
            ->with(['make', 'model', 'lot'])->withCount(['fraudSignals as open_signals_count' => fn ($q) => $q->where('status', 'open')]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** The dealer-side VehiclePolicy is per lot; in the admin panel, only platform admins get here. */
    public static function can(string $action, ?Model $record = null): bool
    {
        return in_array($action, ['viewAny', 'view'], true) && (Auth::user()?->isAdmin() ?? false);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('listed_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('slug')->label('Car')->formatStateUsing(fn (Vehicle $v) => $v->title() ?: 'Untitled draft')
                    ->description(fn (Vehicle $v) => $v->vinTail() ? 'VIN ···'.$v->vinTail() : null)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($w) => $w->where('vehicles.slug', 'like', '%'.str($search)->slug().'%')->orWhere('vehicles.vin', 'like', "%{$search}%")))
                    ->url(fn (Vehicle $v) => $v->listed_at ? url($v->publicPath()) : null, shouldOpenInNewTab: true),
                Tables\Columns\TextColumn::make('lot.name')->label('Lot')->searchable(),
                Tables\Columns\TextColumn::make('price')->formatStateUsing(fn (Vehicle $v) => $v->formattedPrice())->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn (VehicleStatus $state) => $state->label()),
                Tables\Columns\IconColumn::make('held_at')->label('Held')->boolean()->getStateUsing(fn (Vehicle $v) => $v->isHeld())
                    ->tooltip(fn (Vehicle $v) => $v->held_reason),
                Tables\Columns\TextColumn::make('open_signals_count')->label('Signals')->badge()->color(fn (int $state) => $state > 0 ? 'warning' : 'gray'),
                Tables\Columns\IconColumn::make('inspection_id')->label('Inspected')->boolean()->getStateUsing(fn (Vehicle $v) => $v->inspection_id !== null)->toggleable(),
                Tables\Columns\TextColumn::make('listed_at')->since()->sortable()->placeholder('Draft'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(collect(VehicleStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                Tables\Filters\TernaryFilter::make('held_at')->label('Held for review')->nullable(),
                Tables\Filters\Filter::make('signals')->label('Has open signals')->query(fn (Builder $query) => $query->whereHas('fraudSignals', fn ($s) => $s->where('status', 'open'))),
            ])
            ->actions([
                Tables\Actions\Action::make('hide')->icon('heroicon-o-eye-slash')->color('danger')
                    ->visible(fn (Vehicle $v) => ! $v->isHeld() && $v->listed_at !== null)
                    ->form([Forms\Components\TextInput::make('reason')->label('Reason the lot will see')->required()->maxLength(160)])
                    ->action(function (Vehicle $v, array $data): void {
                        app(ModerateListing::class)->hide($v, self::admin(), $data['reason']);
                        Notification::make()->title('Listing hidden')->success()->send();
                    }),
                Tables\Actions\Action::make('release')->label('Put back on sale')->icon('heroicon-o-arrow-uturn-left')->color('success')
                    ->visible(fn (Vehicle $v) => $v->isHeld())
                    ->requiresConfirmation()
                    ->action(function (Vehicle $v): void {
                        app(ModerateListing::class)->approve($v, self::admin());
                        Notification::make()->title('Listing back on sale')->success()->send();
                    }),
            ]);
    }

    private static function admin(): User
    {
        /** @var User $admin */
        $admin = Auth::user();

        return $admin;
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListVehicles::route('/')];
    }
}
