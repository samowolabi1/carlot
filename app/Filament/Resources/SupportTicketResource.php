<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminArea;
use App\Domain\Admin\AdminCounters;
use App\Domain\Helpdesk\Actions\ChangeTicketStatus;
use App\Domain\Helpdesk\Enums\TicketCategory;
use App\Domain\Helpdesk\Enums\TicketPriority;
use App\Domain\Helpdesk\Enums\TicketStatus;
use App\Domain\Helpdesk\Models\SupportTicket;
use App\Filament\Resources\Concerns\AdminsOnly;
use App\Filament\Resources\SupportTicketResource\Pages;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

/** Support desk: tickets from lots. Reply, add internal notes, assign, and set status on the ticket page. */
class SupportTicketResource extends Resource
{
    use AdminsOnly;

    public static function adminAreas(): array
    {
        return [AdminArea::Support];
    }

    // Global search (Ctrl/⌘ K): a ticket by reference or subject.

    /** @return list<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'subject'];
    }

    protected static ?string $model = SupportTicket::class;

    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?string $navigationGroup = 'Support';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Support tickets';

    protected static ?string $recordRouteKeyName = 'ulid';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getNavigationBadge(): ?string
    {
        return AdminCounters::badge('tickets');
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return AdminCounters::all()['urgent_tickets'] ? 'danger' : 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes()->with(['lot' => fn ($q) => $q->withTrashed(), 'assignee', 'opener']);
    }

    public static function canCreate(): bool
    {
        return false; // lots open tickets from their dashboard
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_message_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('unread')->label('')->badge()->color('warning')
                    ->state(fn (SupportTicket $record) => $record->isUnreadByAdmin() ? 'New' : null),
                Tables\Columns\TextColumn::make('subject')->searchable()->limit(60)->weight('semibold')
                    ->description(fn (SupportTicket $record) => "{$record->reference} · ".($record->lot->name ?? 'Deleted lot')),
                Tables\Columns\TextColumn::make('reference')->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('lot.name')->label('Lot')->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('category')->formatStateUsing(fn (TicketCategory $state) => $state->label())->badge()->color('gray'),
                Tables\Columns\TextColumn::make('priority')->formatStateUsing(fn (TicketPriority $state) => $state->short())->badge()->sortable()
                    ->color(fn (TicketPriority $state) => match ($state) {
                        TicketPriority::Urgent => 'danger',
                        TicketPriority::High => 'warning',
                        TicketPriority::Normal => 'info',
                        TicketPriority::Low => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')->formatStateUsing(fn (TicketStatus $state) => $state->label())->badge()
                    ->color(fn (TicketStatus $state) => self::statusColor($state)),
                Tables\Columns\TextColumn::make('assignee.name')->label('Assigned')->placeholder('Nobody'),
                Tables\Columns\TextColumn::make('last_message_at')->label('Last message')->since()->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Opened')->since()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->multiple()
                    ->options(collect(TicketStatus::cases())->mapWithKeys(fn (TicketStatus $s) => [$s->value => $s->label()]))
                    ->default([TicketStatus::Open->value, TicketStatus::Pending->value]),
                Tables\Filters\SelectFilter::make('priority')->options(collect(TicketPriority::cases())->mapWithKeys(fn (TicketPriority $p) => [$p->value => $p->short()])),
                Tables\Filters\SelectFilter::make('category')->options(collect(TicketCategory::cases())->mapWithKeys(fn (TicketCategory $c) => [$c->value => $c->label()])),
                Tables\Filters\Filter::make('mine')->label('Assigned to me')->query(fn (Builder $query) => $query->where('assigned_to', Auth::id())),
                Tables\Filters\Filter::make('unassigned')->label('Nobody assigned')->query(fn (Builder $query) => $query->whereNull('assigned_to')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Open'),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('close')->label('Close tickets')->icon('heroicon-o-archive-box')->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        /** @var User $admin */
                        $admin = Auth::user();
                        foreach ($records as $ticket) {
                            if ($ticket instanceof SupportTicket) {
                                app(ChangeTicketStatus::class)->byAdmin($ticket, $admin, TicketStatus::Closed);
                            }
                        }
                    }),
            ]);
    }

    public static function statusColor(TicketStatus $state): string
    {
        return match ($state) {
            TicketStatus::Open => 'warning',
            TicketStatus::Pending => 'info',
            TicketStatus::Resolved => 'success',
            TicketStatus::Closed => 'gray',
        };
    }

    /** @return array<int|string, string> */
    public static function adminOptions(): array
    {
        return User::query()->adminsFor(AdminArea::Support)->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportTickets::route('/'),
            'view' => Pages\ViewSupportTicket::route('/{record}'),
        ];
    }
}
