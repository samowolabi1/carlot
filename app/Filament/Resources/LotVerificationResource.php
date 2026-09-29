<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminCounters;
use App\Domain\Trust\Actions\DecideLotVerification;
use App\Domain\Trust\Enums\VerificationStatus;
use App\Domain\Trust\Models\LotVerification;
use App\Filament\Resources\Concerns\AdminsOnly;
use App\Filament\Resources\LotVerificationResource\Pages;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Throwable;

/** Lots to verify (design A1): the CAC certificate and frontage photo; approve or reject with a note. */
class LotVerificationResource extends Resource
{
    use AdminsOnly;

    protected static ?string $model = LotVerification::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Review queue';

    protected static ?string $navigationLabel = 'Lots to verify';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordRouteKeyName = 'ulid';

    public static function getNavigationBadge(): ?string
    {
        return AdminCounters::badge('verifications');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes()->with(['lot' => fn ($q) => $q->withTrashed(), 'reviewer']);
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
                Tables\Columns\TextColumn::make('lot.name')->label('Lot')->searchable()->description(fn (LotVerification $v) => collect([$v->lot->city, $v->lot->state])->filter()->implode(', ')),
                Tables\Columns\TextColumn::make('cac_number')->label('CAC')->formatStateUsing(fn (LotVerification $v) => $v->cacLabel())->searchable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (VerificationStatus $state) => $state->label())
                    ->color(fn (VerificationStatus $state) => match ($state) {
                        VerificationStatus::Submitted => 'warning',
                        VerificationStatus::Approved => 'success',
                        VerificationStatus::Rejected => 'danger',
                    }),
                Tables\Columns\TextColumn::make('created_at')->label('Sent')->since()->sortable(),
                Tables\Columns\TextColumn::make('reviewer.name')->label('Checked by')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('notes')->limit(50)->placeholder('—')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(collect(VerificationStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()]))->default(VerificationStatus::Submitted->value),
            ])
            ->actions([
                Tables\Actions\Action::make('certificate')->label('CAC certificate')->icon('heroicon-o-document-text')
                    ->url(fn (LotVerification $v) => $v->fileUrl('certificate'), shouldOpenInNewTab: true),
                Tables\Actions\Action::make('frontage')->label('Frontage')->icon('heroicon-o-photo')
                    ->url(fn (LotVerification $v) => $v->fileUrl('frontage'), shouldOpenInNewTab: true),
                Tables\Actions\Action::make('approve')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (LotVerification $v) => $v->status === VerificationStatus::Submitted)
                    ->form([Forms\Components\Textarea::make('notes')->label('Note (optional)')->rows(2)->maxLength(500)])
                    ->modalDescription('Check the CAC number on the certificate and that the photo matches the lot\'s address and pin.')
                    ->action(fn (LotVerification $v, array $data) => self::decide(fn (DecideLotVerification $d, User $admin) => $d->approve($v, $admin, $data['notes'] ?? null), 'Lot verified')),
                Tables\Actions\Action::make('reject')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (LotVerification $v) => $v->status === VerificationStatus::Submitted)
                    ->form([Forms\Components\Textarea::make('notes')->label('What should the lot fix?')->required()->rows(3)->maxLength(500)])
                    ->action(fn (LotVerification $v, array $data) => self::decide(fn (DecideLotVerification $d, User $admin) => $d->reject($v, $admin, $data['notes']), 'Sent back to the lot')),
            ]);
    }

    /** @param callable(DecideLotVerification, User): mixed $decision */
    private static function decide(callable $decision, string $done): void
    {
        try {
            /** @var User $admin */
            $admin = Auth::user();
            $decision(app(DecideLotVerification::class), $admin);
            Notification::make()->title($done)->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListLotVerifications::route('/')];
    }
}
