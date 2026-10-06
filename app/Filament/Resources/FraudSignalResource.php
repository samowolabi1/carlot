<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminArea;
use App\Domain\Admin\AdminCounters;
use App\Domain\Trust\Actions\ModerateListing;
use App\Domain\Trust\Enums\FraudSignalType;
use App\Domain\Trust\Enums\SignalStatus;
use App\Domain\Trust\Models\FraudSignal;
use App\Domain\Trust\Notifications\ModerationNotice;
use App\Filament\Resources\Concerns\AdminsOnly;
use App\Filament\Resources\FraudSignalResource\Pages;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/** Flagged listings (design A1): duplicate VIN or photo, very low price, new-lot bursts. */
class FraudSignalResource extends Resource
{
    use AdminsOnly;

    public static function adminAreas(): array
    {
        return [AdminArea::Moderation];
    }

    protected static ?string $model = FraudSignal::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $navigationGroup = 'Review queue';

    protected static ?string $navigationLabel = 'Flagged listings';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        return AdminCounters::badge('signals');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['vehicle.make', 'vehicle.model', 'vehicle.lot', 'related.lot', 'related.make', 'related.model']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('vehicle_id')->label('Listing')
                    ->formatStateUsing(fn (FraudSignal $s) => trim($s->vehicle->title().' · '.($s->vehicle->formattedPrice() ?? '')))
                    ->description(fn (FraudSignal $s) => $s->vehicle->lot->name.($s->vehicle->listed_at ? ' · listed '.$s->vehicle->listed_at->diffForHumans() : '').($s->vehicle->isHeld() ? ' · held' : ''))
                    ->url(fn (FraudSignal $s) => url($s->vehicle->publicPath()), shouldOpenInNewTab: true),
                Tables\Columns\TextColumn::make('type')->label('Signal')->badge()->color('warning')->formatStateUsing(fn (FraudSignal $s) => $s->label()),
                Tables\Columns\TextColumn::make('related_vehicle_id')->label('Other listing')->placeholder('—')
                    ->formatStateUsing(fn (FraudSignal $s) => $s->related ? $s->related->lot->name.' · '.($s->related->formattedPrice() ?? '').($s->related->lot->verified_at ? ' · verified' : '') : null)
                    ->description(fn (FraudSignal $s) => $s->related?->listed_at ? 'listed '.$s->related->listed_at->format('j M') : null)
                    ->url(fn (FraudSignal $s) => $s->related ? url($s->related->publicPath()) : null, shouldOpenInNewTab: true),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn (SignalStatus $state) => $state === SignalStatus::Open ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('created_at')->label('Flagged')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(collect(SignalStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)]))->default(SignalStatus::Open->value),
                Tables\Filters\SelectFilter::make('type')->options([
                    FraudSignalType::DuplicateVin->value => 'Duplicate VIN',
                    FraudSignalType::DuplicatePhoto->value => 'Duplicate photo',
                    FraudSignalType::LowPrice->value => 'Low price',
                    FraudSignalType::ListingBurst->value => 'New-seller burst',
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (FraudSignal $s) => $s->status === SignalStatus::Open)
                    ->requiresConfirmation()->modalDescription('Clears every open signal and report on this listing; a held listing goes back on sale.')
                    ->action(function (FraudSignal $s): void {
                        app(ModerateListing::class)->approve($s->vehicle, self::admin());
                        Notification::make()->title('Listing approved')->success()->send();
                    }),
                Tables\Actions\Action::make('hide')->icon('heroicon-o-eye-slash')->color('danger')
                    ->visible(fn (FraudSignal $s) => $s->status === SignalStatus::Open)
                    ->form([Forms\Components\TextInput::make('reason')->label('Reason the seller will see')->required()->maxLength(160)->default(fn (FraudSignal $s) => $s->label())])
                    ->action(function (FraudSignal $s, array $data): void {
                        app(ModerateListing::class)->hide($s->vehicle, self::admin(), $data['reason']);
                        Notification::make()->title('Listing hidden')->success()->send();
                    }),
                Tables\Actions\Action::make('message')->label('Message sellers')->icon('heroicon-o-chat-bubble-left-right')
                    ->form([Forms\Components\Textarea::make('note')->label('Note to the seller(s)')->required()->rows(3)->maxLength(500)
                        ->default(fn (FraudSignal $s) => $s->type === FraudSignalType::DuplicateVin ? 'Please confirm which seller currently holds this car.' : null)])
                    ->action(function (FraudSignal $s, array $data): void {
                        collect([$s->vehicle->lot, $s->related?->lot])->filter()->unique('id')
                            ->each(fn ($lot) => $lot->owner->notify(new ModerationNotice($lot, 'From CarYard about '.$s->vehicle->title().': '.$data['note'])));
                        Notification::make()->title('Message sent')->success()->send();
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
        return ['index' => Pages\ListFraudSignals::route('/')];
    }
}
