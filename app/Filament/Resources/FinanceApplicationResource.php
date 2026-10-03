<?php

namespace App\Filament\Resources;

use App\Domain\Admin\AdminArea;
use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Models\FinanceMessage;
use App\Filament\Resources\Concerns\AdminsOnly;
use App\Filament\Resources\FinanceApplicationResource\Pages;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Every car loan application across lenders, read-only: who applied for which car with which lender, the amount and
 * where it stands. Admins never see the buyer's income, commitments or employer here, nor the messages between the
 * buyer and the lender; the timeline shows only what happened.
 */
class FinanceApplicationResource extends Resource
{
    use AdminsOnly;

    public static function adminAreas(): array
    {
        return [AdminArea::Loans];
    }

    protected static ?string $model = FinanceApplication::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Car loans';

    protected static ?string $navigationLabel = 'Applications';

    protected static ?string $modelLabel = 'car loan application';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordRouteKeyName = 'ulid';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'lender', 'assignee', 'lot' => fn ($q) => $q->withTrashed(), 'vehicle.make', 'vehicle.model']);
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
                Tables\Columns\TextColumn::make('created_at')->label('Sent')->dateTime('j M Y, H:i', 'Africa/Lagos')->sortable(),
                Tables\Columns\TextColumn::make('user.name')->label('Buyer')->searchable(),
                Tables\Columns\TextColumn::make('vehicle_id')->label('Car')->state(fn (FinanceApplication $a) => $a->vehicle?->title() ?? '—')
                    ->description(fn (FinanceApplication $a) => $a->lot?->name),
                Tables\Columns\TextColumn::make('lender.name')->label('Lender')->searchable(),
                Tables\Columns\TextColumn::make('amount')->label('Loan')->formatStateUsing(fn (FinanceApplication $a) => $a->money())->description(fn (FinanceApplication $a) => "{$a->tenor_months} months")->sortable(),
                Tables\Columns\TextColumn::make('approved_amount')->label('Approved')->formatStateUsing(fn (FinanceApplication $a) => $a->approved_amount ? $a->money($a->approved_amount) : null)->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('disbursed_amount')->label('Paid to seller')->formatStateUsing(fn (FinanceApplication $a) => $a->disbursed_amount ? $a->money($a->disbursed_amount) : null)->placeholder('—')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn (FinanceStatus $state) => $state->label())
                    ->color(fn (FinanceStatus $state) => match ($state->tone()) {
                        'good' => 'success', 'action' => 'warning', 'bad' => 'danger', 'closed' => 'gray', default => 'info',
                    }),
                Tables\Columns\TextColumn::make('updated_at')->label('Last change')->since()->sortable()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->multiple()->options(collect(FinanceStatus::cases())->mapWithKeys(fn (FinanceStatus $s) => [$s->value => $s->label()])),
                Tables\Filters\SelectFilter::make('lender')->relationship('lender', 'name')->preload(),
                Tables\Filters\Filter::make('created_at')->label('Sent between')
                    ->form([Forms\Components\DatePicker::make('from'), Forms\Components\DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d, 'Africa/Lagos')->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d, 'Africa/Lagos')->endOfDay()->utc()))),
            ])
            ->actions([Tables\Actions\ViewAction::make()->slideOver()]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(3)->schema([
                Infolists\Components\TextEntry::make('user.name')->label('Buyer'),
                Infolists\Components\TextEntry::make('lender.name')->label('Lender'),
                Infolists\Components\TextEntry::make('assignee.name')->label('Lender officer')->placeholder('Whole team'),
                Infolists\Components\TextEntry::make('vehicle_id')->label('Car')->state(fn (FinanceApplication $a) => $a->vehicle?->title() ?? '—'),
                Infolists\Components\TextEntry::make('lot.name')->label('Seller'),
                Infolists\Components\TextEntry::make('status')->badge()->formatStateUsing(fn (FinanceStatus $state) => $state->label()),
                Infolists\Components\TextEntry::make('amount')->label('Loan asked for')->formatStateUsing(fn (FinanceApplication $a) => $a->money()." over {$a->tenor_months} months"),
                Infolists\Components\TextEntry::make('deposit')->formatStateUsing(fn (FinanceApplication $a) => $a->money($a->deposit)),
                Infolists\Components\TextEntry::make('approved_amount')->label('Approved')->formatStateUsing(fn (FinanceApplication $a) => $a->approved_amount ? $a->money($a->approved_amount) : null)->placeholder('—'),
                Infolists\Components\TextEntry::make('disbursed_amount')->label('Paid to the seller')
                    ->formatStateUsing(fn (FinanceApplication $a) => $a->disbursed_amount ? $a->money($a->disbursed_amount).($a->disbursed_reference ? " · {$a->disbursed_reference}" : '') : null)->placeholder('—'),
                Infolists\Components\TextEntry::make('external_ref')->label('Lender reference')->placeholder('—'),
                Infolists\Components\TextEntry::make('consented_at')->label('Buyer consented')->dateTime('j M Y, H:i', 'Africa/Lagos'),
            ]),
            Infolists\Components\Section::make('What happened')->description('Status changes only. Messages and documents stay between the buyer and the lender.')->schema([
                Infolists\Components\TextEntry::make('timeline')->hiddenLabel()->listWithLineBreaks()
                    ->state(fn (FinanceApplication $a) => $a->messages()->where('side', FinanceMessage::SYSTEM)->get()
                        ->map(fn (FinanceMessage $m) => $m->created_at->copy()->setTimezone('Africa/Lagos')->format('j M, H:i').' — '.strtok($m->body, "\n"))->all()),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListFinanceApplications::route('/')];
    }
}
