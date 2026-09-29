<?php

namespace App\Filament\Resources;

use App\Domain\Audit\AuditLog;
use App\Filament\Resources\AuditLogResource\Pages;
use App\Filament\Resources\Concerns\AdminsOnly;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Who changed prices, statuses, staff, payments and moderation decisions (TDD M17), read-only. */
class AuditLogResource extends Resource
{
    use AdminsOnly;

    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'impersonator', 'lot']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('When')->dateTime('j M Y, H:i')->sortable(),
                Tables\Columns\TextColumn::make('action')->badge()->searchable(),
                Tables\Columns\TextColumn::make('user.name')->label('By')->placeholder('System')
                    ->description(fn (AuditLog $l) => $l->impersonator ? 'Done by '.$l->impersonator->name.' (support, "Log in as")' : $l->user?->phone),
                Tables\Columns\TextColumn::make('lot.name')->label('Lot')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('subject_type')->label('Record')->formatStateUsing(fn (AuditLog $l) => $l->subject_type ? class_basename($l->subject_type).' #'.$l->subject_id : null)->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('changes')->formatStateUsing(fn (AuditLog $l) => $l->changes ? json_encode($l->changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null)->limit(80)->wrap()->placeholder('—'),
                Tables\Columns\TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('admin')->label('Admin actions')->query(fn (Builder $query) => $query->where('action', 'like', 'admin.%')),
                Tables\Filters\Filter::make('support')->label('Done through "Log in as"')->query(fn (Builder $query) => $query->whereNotNull('impersonator_id')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListAuditLogs::route('/')];
    }
}
