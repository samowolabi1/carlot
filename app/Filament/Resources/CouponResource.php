<?php

namespace App\Filament\Resources;

use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Models\Coupon;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Lots\Models\Lot;
use App\Filament\Resources\Concerns\AdminsOnly;
use App\Filament\Resources\CouponResource\Pages;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Trial codes, e.g. the launch offer of 3 months on Starter (TDD M16). Create, edit, pause and see
 * who used each code. Changes apply to future redemptions; lots that already used a code keep
 * the trial they got. Used codes can be paused but not deleted.
 */
class CouponResource extends Resource
{
    use AdminsOnly;

    protected static ?string $model = Coupon::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(2)->schema([
                Forms\Components\TextInput::make('code')->required()->maxLength(32)->unique(ignoreRecord: true)->placeholder('LAUNCH3')
                    ->helperText(fn (?Coupon $record) => $record && $record->redeemed > 0
                        ? 'Already used '.$record->redeemed.' '.str('time')->plural($record->redeemed).'. A new code works from now; the old one stops working.'
                        : 'Letters, numbers and dashes. Lots type it on their Billing page.')
                    ->dehydrateStateUsing(fn (?string $state) => strtoupper(trim((string) $state)))
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! preg_match(Coupon::PATTERN, strtoupper(trim((string) $value)))) {
                            $fail('Use 3 to 32 letters, numbers or dashes, e.g. KANO-MEETUP.');
                        }
                    })
                    ->suffixAction(FormAction::make('generate')->icon('heroicon-o-sparkles')->tooltip('Make a random code')
                        ->action(fn (Set $set) => $set('code', 'LL-'.Str::upper(Str::random(6))))),
                Forms\Components\Select::make('plan_id')->label('Trial on plan')->relationship('plan', 'name')->required(),
                Forms\Components\TextInput::make('trial_days')->label('Free days')->numeric()->integer()->minValue(1)->maxValue(365)->required()->default(90)
                    ->helperText('Changing this only affects lots that use the code from now on.'),
                Forms\Components\TextInput::make('max_redemptions')->label('How many lots can use it')->numeric()->integer()->maxValue(1_000_000)
                    ->minValue(fn (?Coupon $record) => max(1, (int) $record?->redeemed))
                    ->helperText(fn (?Coupon $record) => 'Empty for no limit.'.($record && $record->redeemed ? " Used {$record->redeemed} so far." : '')),
                Forms\Components\DateTimePicker::make('expires_at')->label('Stops working on')->seconds(false)
                    ->helperText('Empty: never expires.'),
                Forms\Components\Toggle::make('active')->label('Can be used')->default(true)->inline(false)
                    ->helperText('Off pauses the code without deleting it.'),
                Forms\Components\TextInput::make('note')->label('What it\'s for (only admins see this)')->maxLength(160)->columnSpanFull()
                    ->placeholder('e.g. Kano dealer meetup, January'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable()->copyable()->weight('semibold')->description(fn (Coupon $record) => $record->note),
                Tables\Columns\TextColumn::make('plan.name')->label('Plan'),
                Tables\Columns\TextColumn::make('trial_days')->label('Free')->suffix(' days'),
                Tables\Columns\TextColumn::make('redeemed')->label('Used')->formatStateUsing(fn (Coupon $record) => $record->redeemed.($record->max_redemptions ? " of {$record->max_redemptions}" : '')),
                Tables\Columns\TextColumn::make('state')->label('Status')->badge()
                    ->state(fn (Coupon $record) => self::stateLabel($record->state()))
                    ->color(fn (Coupon $record) => match ($record->state()) {
                        'active' => 'success',
                        'paused' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('expires_at')->label('Expires')->date()->placeholder('Never')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('state')->label('Status')
                    ->options(['active' => 'Active', 'paused' => 'Paused', 'expired' => 'Expired', 'used_up' => 'Used up'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'active' => $query->where('active', true)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                            ->where(fn ($q) => $q->whereNull('max_redemptions')->orWhereColumn('redeemed', '<', 'max_redemptions')),
                        'paused' => $query->where('active', false),
                        'expired' => $query->where('active', true)->where('expires_at', '<=', now()),
                        'used_up' => $query->where('active', true)->whereNotNull('max_redemptions')->whereColumn('redeemed', '>=', 'max_redemptions'),
                        default => $query,
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('toggle')
                    ->label(fn (Coupon $record) => $record->active ? 'Pause' : 'Resume')
                    ->icon(fn (Coupon $record) => $record->active ? 'heroicon-o-pause-circle' : 'heroicon-o-play-circle')
                    ->color('gray')
                    ->action(function (Coupon $record): void {
                        $record->update(['active' => ! $record->active]);
                        AuditLog::record($record->active ? 'admin.coupon_resumed' : 'admin.coupon_paused', $record, ['code' => $record->code]);
                        Notification::make()->title($record->active ? "{$record->code} can be used again" : "{$record->code} is paused")->success()->send();
                    }),
                Tables\Actions\Action::make('used')->label('Who used it')->icon('heroicon-o-users')->color('gray')
                    ->visible(fn (Coupon $record) => (int) $record->redeemed > 0)
                    ->modalHeading(fn (Coupon $record) => "Lots that used {$record->code}")
                    ->modalContent(fn (Coupon $record) => new HtmlString(self::redemptions($record)))
                    ->modalSubmitAction(false),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Coupon $record) => (int) $record->redeemed === 0)
                    ->after(fn (Coupon $record) => AuditLog::record('admin.coupon_deleted', null, ['code' => $record->code])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCoupons::route('/'),
            'create' => Pages\CreateCoupon::route('/create'),
            'edit' => Pages\EditCoupon::route('/{record}/edit'),
        ];
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            'active' => 'Active',
            'paused' => 'Paused',
            'expired' => 'Expired',
            default => 'Used up',
        };
    }

    private static function redemptions(Coupon $coupon): string
    {
        $subs = Subscription::withoutGlobalScopes()->where('coupon_id', $coupon->id)->with('plan')->latest('updated_at')->limit(100)->get();
        $lots = Lot::withTrashed()->whereIn('id', $subs->pluck('lot_id'))->get()->keyBy('id');

        $rows = $subs->map(function (Subscription $s) use ($lots) {
            $lot = $lots->get($s->lot_id);

            return '<tr><td style="padding:6px 8px">'.e($lot->name ?? 'Deleted lot').'</td><td style="padding:6px 8px">'.e(collect([$lot?->city, $lot?->state])->filter()->implode(', '))
                .'</td><td style="padding:6px 8px">'.e($s->status->label()).'</td><td style="padding:6px 8px">'.e($s->trial_ends_at?->format('j M Y') ?? '—').'</td></tr>';
        })->implode('');

        return '<table style="width:100%;font-size:14px;border-collapse:collapse"><thead><tr style="text-align:left;opacity:.7">'
            .'<th style="padding:6px 8px">Lot</th><th style="padding:6px 8px">Where</th><th style="padding:6px 8px">Now</th><th style="padding:6px 8px">Trial ends</th></tr></thead><tbody>'
            .($rows ?: '<tr><td colspan="4" style="padding:6px 8px">No lots yet.</td></tr>').'</tbody></table>';
    }
}
