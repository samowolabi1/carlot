<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminCounters;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Models\Message;
use App\Domain\Lots\Models\Lot;
use App\Domain\Trust\Actions\ModerateListing;
use App\Domain\Trust\Actions\ResolveReport;
use App\Domain\Trust\Enums\ReportReason;
use App\Domain\Trust\Enums\ReportStatus;
use App\Domain\Trust\Models\Report;
use App\Domain\Trust\Models\Review;
use App\Filament\Resources\Concerns\AdminsOnly;
use App\Filament\Resources\ReportResource\Pages;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** Reports on listings, lots and chat messages (TDD M14). Reported reviews are in ReviewResource. */
class ReportResource extends Resource
{
    use AdminsOnly;

    protected static ?string $model = Report::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Review queue';

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        return AdminCounters::badge('reports');
    }

    public static function getEloquentQuery(): Builder
    {
        // Reported cars need their make and model for the title (loading them one by one failed with
        // lazy loading off, and was a query per row otherwise).
        return parent::getEloquentQuery()->where('reportable_type', '!=', Review::class)->with(['reporter', 'lot'])
            ->with(['reportable' => function (Relation $morph): void {
                if ($morph instanceof MorphTo) {
                    $morph->morphWith([Vehicle::class => ['make', 'model']]);
                }
            }]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** What was reported, in a line: the car, the lot, or the message text. */
    public static function subject(Report $r): string
    {
        $item = $r->reportable;

        return match (true) {
            $item instanceof Vehicle => 'Listing: '.$item->title().($item->isHeld() ? ' (held)' : ''),
            $item instanceof Lot => 'Lot: '.$item->name,
            $item instanceof Message => 'Message: "'.Str::limit($item->body, 90).'"',
            default => 'Removed content',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('reportable_id')->label('Reported')->formatStateUsing(fn (Report $r) => self::subject($r))->wrap()
                    ->url(fn (Report $r) => match (true) {
                        $r->reportable instanceof Vehicle => url($r->reportable->publicPath()),
                        $r->reportable instanceof Lot => route('lots.show', $r->reportable),
                        default => null,
                    }, shouldOpenInNewTab: true),
                Tables\Columns\TextColumn::make('lot.name')->label('Lot')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('reason')->badge()->formatStateUsing(fn (ReportReason $state) => $state->label())
                    ->description(fn (Report $r) => $r->details ? Str::limit($r->details, 80) : null),
                Tables\Columns\TextColumn::make('reporter.name')->label('By')->placeholder('—')->description(fn (Report $r) => $r->reporter?->phone)->toggleable(),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn (ReportStatus $state) => $state === ReportStatus::Open ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('created_at')->label('Sent')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(collect(ReportStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)]))->default(ReportStatus::Open->value),
                Tables\Filters\SelectFilter::make('reportable_type')->label('Kind')->options([Vehicle::class => 'Listings', Lot::class => 'Lots', Message::class => 'Messages']),
            ])
            ->actions([
                // Listings: the same decision as a flagged listing, closing every report on it.
                Tables\Actions\Action::make('hide')->label('Hide listing')->icon('heroicon-o-eye-slash')->color('danger')
                    ->visible(fn (Report $r) => $r->status === ReportStatus::Open && $r->reportable instanceof Vehicle)
                    ->form([Forms\Components\TextInput::make('reason')->label('Reason the lot will see')->required()->maxLength(160)->default(fn (Report $r) => $r->reason->label())])
                    ->action(function (Report $r, array $data): void {
                        /** @var Vehicle $vehicle */
                        $vehicle = $r->reportable;
                        app(ModerateListing::class)->hide($vehicle, self::admin(), $data['reason']);
                        Notification::make()->title('Listing hidden')->success()->send();
                    }),
                Tables\Actions\Action::make('keep')->label('Keep listing')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (Report $r) => $r->status === ReportStatus::Open && $r->reportable instanceof Vehicle)
                    ->requiresConfirmation()->modalDescription('Dismisses every open report on this listing and puts it back on sale if it was held.')
                    ->action(function (Report $r): void {
                        /** @var Vehicle $vehicle */
                        $vehicle = $r->reportable;
                        app(ModerateListing::class)->approve($vehicle, self::admin());
                        Notification::make()->title('Listing kept')->success()->send();
                    }),
                Tables\Actions\Action::make('actioned')->label('Dealt with')->icon('heroicon-o-check-badge')
                    ->visible(fn (Report $r) => $r->status === ReportStatus::Open && ! $r->reportable instanceof Vehicle)
                    ->requiresConfirmation()->modalDescription('Use after acting on it, e.g. suspending the lot or contacting the sender.')
                    ->action(fn (Report $r) => app(ResolveReport::class)->run($r, self::admin(), ReportStatus::Actioned)),
                Tables\Actions\Action::make('dismiss')->icon('heroicon-o-x-mark')->color('gray')
                    ->visible(fn (Report $r) => $r->status === ReportStatus::Open && ! $r->reportable instanceof Vehicle)
                    ->action(fn (Report $r) => app(ResolveReport::class)->run($r, self::admin(), ReportStatus::Dismissed)),
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
        return ['index' => Pages\ListReports::route('/')];
    }
}
