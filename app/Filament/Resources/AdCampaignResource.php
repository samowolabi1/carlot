<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminCounters;
use App\Domain\Advertising\Actions\ReviewAdCampaign;
use App\Domain\Advertising\Enums\AdPlacement;
use App\Domain\Advertising\Enums\AdStatus;
use App\Domain\Advertising\Models\AdCampaign;
use App\Domain\Inventory\Models\Make;
use App\Filament\Resources\AdCampaignResource\Pages;
use App\Filament\Resources\Concerns\AdminsOnly;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Throwable;

/** Adverts lots paid for: check each before it runs (approve, or reject and refund), and take down if needed. */
class AdCampaignResource extends Resource
{
    use AdminsOnly;

    protected static ?string $model = AdCampaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Review queue';

    protected static ?string $navigationLabel = 'Adverts';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordRouteKeyName = 'ulid';

    public static function getNavigationBadge(): ?string
    {
        return AdminCounters::badge('adverts');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes()->with(['lot' => fn ($q) => $q->withTrashed(), 'vehicle.cover', 'vehicle.make', 'vehicle.model', 'reviewer']);
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
                Tables\Columns\ImageColumn::make('banner')->label('')->state(fn (AdCampaign $record) => $record->imageUrl())->width(160)->height(60),
                Tables\Columns\TextColumn::make('headline')->searchable()->weight('semibold')->wrap()
                    ->description(fn (AdCampaign $record) => collect([$record->subtext, $record->cta->label().' → '.($record->vehicle?->title() ?? 'lot page')])->filter()->implode(' · ')),
                Tables\Columns\TextColumn::make('lot.name')->label('Lot')->searchable(),
                Tables\Columns\TextColumn::make('placement')->formatStateUsing(fn (AdPlacement $state) => $state->label())->badge()->color('gray')
                    ->description(fn (AdCampaign $record) => self::targeting($record)),
                Tables\Columns\TextColumn::make('state')->label('Status')->badge()
                    ->state(fn (AdCampaign $record) => AdCampaign::stateLabel($record->state()))
                    ->color(fn (AdCampaign $record) => match ($record->state()) {
                        'in_review' => 'warning',
                        'live' => 'success',
                        'scheduled' => 'info',
                        'rejected', 'removed' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('dates')->label('Runs')
                    ->state(fn (AdCampaign $record) => $record->starts_at
                        ? $record->starts_at->setTimezone((string) config('lotlink.timezone'))->format('j M').' – '.$record->ends_at?->setTimezone((string) config('lotlink.timezone'))->format('j M Y')
                        : 'Asked from '.$record->requested_start->format('j M Y').' · '.$record->days.' days'),
                Tables\Columns\TextColumn::make('price')->formatStateUsing(fn (AdCampaign $record) => $record->money()),
                Tables\Columns\TextColumn::make('results')->label('Views / clicks')
                    ->state(fn (AdCampaign $record) => number_format($record->impressions).' / '.number_format($record->clicks).($record->ctr() !== null ? " ({$record->ctr()}%)" : '')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    AdStatus::InReview->value => 'To check',
                    AdStatus::Approved->value => 'Approved',
                    AdStatus::Rejected->value => 'Rejected',
                    AdStatus::Removed->value => 'Removed',
                    AdStatus::Draft->value => 'Unpaid',
                ])->default(AdStatus::InReview->value),
                Tables\Filters\SelectFilter::make('placement')->options(collect(AdPlacement::cases())->mapWithKeys(fn (AdPlacement $p) => [$p->value => $p->label()])),
            ])
            ->actions([
                Tables\Actions\Action::make('preview')->label('Preview')->icon('heroicon-o-eye')->color('gray')
                    ->modalHeading(fn (AdCampaign $record) => $record->headline)
                    ->modalContent(fn (AdCampaign $record) => new HtmlString(self::preview($record)))
                    ->modalSubmitAction(false),
                Tables\Actions\Action::make('approve')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (AdCampaign $record) => $record->status === AdStatus::InReview)
                    ->requiresConfirmation()
                    ->modalDescription('Check the image and words are the lot\'s own, honest and suitable for everyone. It runs from the date asked for, or the next free slot.')
                    ->action(fn (AdCampaign $record) => self::review(fn (ReviewAdCampaign $r, User $admin) => $r->approve($record, $admin), 'Advert approved')),
                Tables\Actions\Action::make('reject')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (AdCampaign $record) => $record->status === AdStatus::InReview)
                    ->form([Forms\Components\Textarea::make('note')->label('Why? (the lot sees this)')->required()->rows(3)->maxLength(250)])
                    ->modalDescription('The lot is refunded in full and told why.')
                    ->action(fn (AdCampaign $record, array $data) => self::review(fn (ReviewAdCampaign $r, User $admin) => $r->reject($record, $admin, $data['note']), 'Rejected and refunded')),
                Tables\Actions\Action::make('remove')->label('Take down')->icon('heroicon-o-no-symbol')->color('danger')
                    ->visible(fn (AdCampaign $record) => $record->status === AdStatus::Approved && in_array($record->state(), ['scheduled', 'live'], true))
                    ->form([Forms\Components\Textarea::make('note')->label('Why? (the lot sees this)')->required()->rows(3)->maxLength(250)])
                    ->modalDescription('Stops the advert now. No refund is made automatically; refund from Payments if one is owed.')
                    ->action(fn (AdCampaign $record, array $data) => self::review(fn (ReviewAdCampaign $r, User $admin) => $r->remove($record, $admin, $data['note']), 'Advert taken down')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAdCampaigns::route('/')];
    }

    private static function targeting(AdCampaign $record): ?string
    {
        $aim = $record->targeting ?? [];
        $make = ! empty($aim['make_id']) ? Make::query()->whereKey($aim['make_id'])->value('name') : null;

        return collect([$make, $aim['body_type'] ?? null, $aim['city'] ?? null])->filter()->implode(' · ') ?: null;
    }

    private static function preview(AdCampaign $record): string
    {
        $image = $record->imageUrl();
        [$w, $h] = $record->placement->size();

        return '<div style="position:relative;border-radius:12px;overflow:hidden;aspect-ratio:'.$w.'/'.$h.';background:#16302B">'
            .($image ? '<img src="'.e($image).'" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover">' : '')
            .'<div style="position:absolute;inset:0;background:linear-gradient(90deg,rgba(0,0,0,.65),rgba(0,0,0,0) 70%);"></div>'
            .'<div style="position:absolute;left:20px;bottom:18px;right:30%;color:#fff">'
            .'<div style="font-size:11px;opacity:.8">Sponsored · '.e($record->lot->name).'</div>'
            .'<div style="font-size:20px;font-weight:700;line-height:1.2">'.e($record->headline).'</div>'
            .($record->subtext ? '<div style="font-size:13px;opacity:.9">'.e($record->subtext).'</div>' : '')
            .'<div style="margin-top:8px;display:inline-block;background:#C2410C;border-radius:8px;padding:6px 12px;font-size:13px;font-weight:600">'.e($record->cta->label()).'</div>'
            .'</div></div>';
    }

    /** @param callable(ReviewAdCampaign, User): mixed $decision */
    private static function review(callable $decision, string $done): void
    {
        try {
            /** @var User $admin */
            $admin = Auth::user();
            $decision(app(ReviewAdCampaign::class), $admin);
            Notification::make()->title($done)->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
