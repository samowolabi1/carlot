<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminArea;
use App\Domain\Admin\AdminCounters;
use App\Domain\Trust\Actions\ModerateReview;
use App\Domain\Trust\Enums\ReportStatus;
use App\Domain\Trust\Enums\ReviewStatus;
use App\Domain\Trust\Models\Review;
use App\Filament\Resources\Concerns\AdminsOnly;
use App\Filament\Resources\ReviewResource\Pages;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** All reviews; reported ones wait here, hidden, until an admin restores or removes them. */
class ReviewResource extends Resource
{
    use AdminsOnly;

    public static function adminAreas(): array
    {
        return [AdminArea::Moderation];
    }

    protected static ?string $model = Review::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationGroup = 'Review queue';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordRouteKeyName = 'ulid';

    public static function getNavigationBadge(): ?string
    {
        return AdminCounters::badge('reviews');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes()->with(['lot', 'author'])
            ->withCount(['reports as open_reports_count' => fn ($q) => $q->where('status', ReportStatus::Open)]);
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
                Tables\Columns\TextColumn::make('lot.name')->label('Seller')->searchable(),
                Tables\Columns\TextColumn::make('rating')->formatStateUsing(fn (int $state) => "{$state} / 5")->sortable(),
                Tables\Columns\TextColumn::make('body')->label('Review')->wrap()->limit(160)->placeholder('No text')->searchable()
                    ->description(fn (Review $r) => $r->authorName().($r->reply ? ' · seller replied: "'.Str::limit($r->reply, 60).'"' : '')),
                Tables\Columns\TextColumn::make('open_reports_count')->label('Open reports')->badge()->color(fn (int $state) => $state > 0 ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn (ReviewStatus $state) => $state === ReviewStatus::Visible ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('created_at')->label('Posted')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('reported')->label('Reported, waiting')->default()
                    ->query(fn (Builder $query) => $query->whereHas('reports', fn ($r) => $r->where('status', ReportStatus::Open))),
                Tables\Filters\SelectFilter::make('status')->options([ReviewStatus::Visible->value => 'Visible', ReviewStatus::Hidden->value => 'Hidden']),
            ])
            ->actions([
                Tables\Actions\Action::make('restore')->label('Show again')->icon('heroicon-o-eye')->color('success')
                    ->visible(fn (Review $r) => $r->status === ReviewStatus::Hidden || $r->getAttribute('open_reports_count') > 0)
                    ->requiresConfirmation()->modalDescription('The review goes back on the seller\'s page and its open reports are dismissed.')
                    ->action(function (Review $r): void {
                        app(ModerateReview::class)->restore($r, self::admin());
                        Notification::make()->title('Review visible')->success()->send();
                    }),
                Tables\Actions\Action::make('remove')->icon('heroicon-o-trash')->color('danger')
                    ->visible(fn (Review $r) => $r->status === ReviewStatus::Visible || $r->getAttribute('open_reports_count') > 0)
                    ->requiresConfirmation()->modalDescription('The review stays hidden for good and no longer counts towards the rating.')
                    ->action(function (Review $r): void {
                        app(ModerateReview::class)->remove($r, self::admin());
                        Notification::make()->title('Review removed')->success()->send();
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
        return ['index' => Pages\ListReviews::route('/')];
    }
}
