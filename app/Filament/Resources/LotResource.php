<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\Impersonation;
use App\Domain\Audit\AuditLog;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\Plan;
use App\Domain\Support\Regions;
use App\Domain\Trust\Actions\DecideLotVerification;
use App\Filament\Resources\LotResource\Pages;
use Filament\Forms;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;

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
                Tables\Columns\IconColumn::make('verified_at')->label('Verified')->boolean()->getStateUsing(fn (Lot $lot) => $lot->isVerified()),
                Tables\Columns\TextColumn::make('rating')->formatStateUsing(fn (Lot $lot) => $lot->rating ? number_format($lot->rating, 1).' ★ ('.$lot->reviews_count.')' : null)->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('submitted_at')->since()->placeholder('Still onboarding')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(collect(LotStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                Tables\Filters\SelectFilter::make('state')->options(collect(Regions::options())->mapWithKeys(fn (array $r) => [$r['value'] => $r['label']]))->searchable(),
                Tables\Filters\TernaryFilter::make('onboarded_by')->label('Onboarded by LotLink')->nullable(),
                Tables\Filters\TernaryFilter::make('submitted_at')->label('Submitted')->nullable(),
                Tables\Filters\TernaryFilter::make('verified_at')->label('Verified')->nullable(),
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
                Tables\Actions\Action::make('revoke')->label('Remove badge')->icon('heroicon-o-shield-exclamation')->color('danger')
                    ->visible(fn (Lot $lot) => $lot->isVerified())
                    ->form([Forms\Components\TextInput::make('reason')->required()->maxLength(160)])
                    ->action(function (Lot $lot, array $data): void {
                        app(DecideLotVerification::class)->revoke($lot, self::admin(), $data['reason']);
                        Notification::make()->title('Verified badge removed')->success()->send();
                    }),
                // Enterprise isn't self-serve: admins put a lot on it (or any plan) here.
                Tables\Actions\Action::make('plan')->label('Set plan')->icon('heroicon-o-rectangle-stack')->color('gray')
                    ->fillForm(fn (Lot $lot) => ['plan_id' => $lot->plan_id])
                    ->form([Forms\Components\Select::make('plan_id')->label('Plan')->options(fn () => Plan::orderBy('sort')->pluck('name', 'id'))->required()
                        ->helperText('Changes what the lot can use straight away. Billing stays as it is in Payments.')])
                    ->action(function (Lot $lot, array $data): void {
                        $from = $lot->plan?->code;
                        $lot->forceFill(['plan_id' => (int) $data['plan_id']])->save();
                        AuditLog::record('admin.plan_set', $lot, ['from' => $from, 'to' => $lot->fresh()->plan?->code], self::admin(), $lot->id);
                        Notification::make()->title('Plan updated')->success()->send();
                    }),
                // Support (TDD M17): see the dashboard as the owner does; logged in the audit log.
                Tables\Actions\Action::make('impersonate')->label('Log in as owner')->icon('heroicon-o-arrow-right-end-on-rectangle')->color('gray')
                    ->requiresConfirmation()->modalDescription('You will see LotLink as the lot owner. Everything you do is recorded as you, in the audit log.')
                    ->action(function (Lot $lot) {
                        app(Impersonation::class)->start(self::admin(), $lot->owner);

                        return redirect()->route('dealer.dashboard', $lot);
                    }),
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
            Section::make('Trust')->columns(3)->schema([
                TextEntry::make('verified_at')->label('Verified')->dateTime('j M Y')->placeholder('Not verified'),
                TextEntry::make('latestVerification.cac_number')->label('CAC number')->placeholder('Not sent'),
                TextEntry::make('latestVerification.status')->label('Verification')->formatStateUsing(fn ($state) => $state?->label())->placeholder('—'),
                TextEntry::make('rating')->formatStateUsing(fn (Lot $lot) => $lot->rating ? number_format($lot->rating, 1).' from '.$lot->reviews_count.' reviews' : null)->placeholder('No reviews'),
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

    private static function admin(): User
    {
        /** @var User $admin */
        $admin = Auth::user();

        return $admin;
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
