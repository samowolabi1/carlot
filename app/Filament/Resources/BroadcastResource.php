<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminArea;
use App\Domain\Engagement\Actions\SendBroadcast;
use App\Domain\Engagement\Models\Broadcast;
use App\Domain\Engagement\Models\EngagementMessage;
use App\Domain\Engagement\Support\BroadcastAudience;
use App\Domain\Lots\Models\Plan;
use App\Domain\Support\Regions;
use App\Filament\Resources\BroadcastResource\Pages;
use App\Filament\Resources\Concerns\AdminsOnly;
use Carbon\CarbonImmutable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Announcements, tips and promos to sellers: pick the sellers (state, plan, status, verified,
 * activity), how it goes (in-app always; email, push, WhatsApp), then send now or schedule.
 * Shows how many it reached and how many opened the link.
 */
class BroadcastResource extends Resource
{
    use AdminsOnly;

    public static function adminAreas(): array
    {
        return [AdminArea::Communication];
    }

    protected static ?string $model = Broadcast::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Engagement';

    protected static ?string $navigationLabel = 'Broadcasts';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Message')->schema([
                Forms\Components\TextInput::make('title')->label('Title (also the email subject)')->required()->maxLength(120)
                    ->placeholder('New: share your cars to WhatsApp status in one tap'),
                Forms\Components\Textarea::make('body')->label('Message')->required()->maxLength(5000)->rows(8)
                    ->helperText('Plain text. A blank line starts a new paragraph.'),
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('cta_label')->label('Button')->maxLength(40)->placeholder('Try it now'),
                    Forms\Components\TextInput::make('cta_url')->label('Button link')->url()->maxLength(500)
                        ->placeholder(url('/dealer'))->helperText('Empty: the seller\'s dashboard.'),
                ]),
            ]),
            Forms\Components\Section::make('Who gets it')->columns(2)->schema([
                Forms\Components\Select::make('audience.states')->label('States')->multiple()->searchable()->live()
                    ->options(collect(Regions::options())->mapWithKeys(fn (array $r) => [$r['value'] => $r['label']]))->placeholder('All states'),
                Forms\Components\Select::make('audience.plans')->label('Plans')->multiple()->live()
                    ->options(fn () => Plan::query()->orderBy('sort')->pluck('name', 'id'))->placeholder('All plans'),
                Forms\Components\Select::make('audience.status')->label('Seller status')->live()->default('active')->selectablePlaceholder(false)
                    ->options(['active' => 'Live sellers', 'pending' => 'Waiting for approval', 'any' => 'Any (not suspended)']),
                Forms\Components\Select::make('audience.verified')->label('Verified (CAC)')->live()->placeholder('Any')
                    ->options(['yes' => 'Verified only', 'no' => 'Not verified']),
                Forms\Components\Select::make('audience.activity')->label('Activity')->live()->placeholder('Any')->options(BroadcastAudience::ACTIVITY),
                Forms\Components\Toggle::make('audience.managers')->label('Managers too')->live()->inline(false)
                    ->helperText('Owners always get it.'),
                Forms\Components\Placeholder::make('reach')->label('Reaches')->columnSpanFull()
                    ->content(fn (Get $get) => number_format(BroadcastAudience::recipients(self::audience((array) $get('audience')))->count()).' people now (counted again when it sends)'),
            ]),
            Forms\Components\Section::make('How it goes')->schema([
                Forms\Components\CheckboxList::make('channels')->label('As well as the notification centre')->default(['mail', 'push'])->columns(3)
                    ->options(['mail' => 'Email', 'push' => 'Push (phones that turned it on)', 'whatsapp' => 'WhatsApp'])
                    ->helperText('WhatsApp costs per message and needs the approved "lot_announcement" template. Everyone can turn CarYard news off in their settings.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')->weight('semibold')->wrap()->searchable()
                    ->description(fn (Broadcast $record) => BroadcastAudience::describe(self::audience($record->audience))),
                Tables\Columns\TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        'sent' => 'success', 'scheduled' => 'info', 'sending' => 'warning', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('when')->label('Sent / scheduled')
                    ->state(fn (Broadcast $record) => ($record->sent_at ?? $record->scheduled_at)?->timezone((string) config('lotlink.timezone'))->format('j M Y, H:i'))->placeholder('—'),
                Tables\Columns\TextColumn::make('recipients_count')->label('Reached')->numeric(),
                Tables\Columns\TextColumn::make('opened')->label('Opened')
                    ->state(fn (Broadcast $record) => self::opened($record)),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->visible(fn (Broadcast $record) => $record->isEditable()),
                Tables\Actions\Action::make('send')->label('Send now')->icon('heroicon-o-paper-airplane')->color('primary')
                    ->visible(fn (Broadcast $record) => $record->isEditable())
                    ->requiresConfirmation()
                    ->modalHeading(fn (Broadcast $record) => "Send \"{$record->title}\"?")
                    ->modalDescription(fn (Broadcast $record) => 'It goes to '.number_format(BroadcastAudience::recipients(self::audience($record->audience))->count()).' people now. This can\'t be undone.')
                    ->action(function (Broadcast $record, SendBroadcast $send): void {
                        try {
                            $send->run($record, self::admin());
                            Notification::make()->title('Sending')->body('It goes out in the next few minutes.')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                        }
                    }),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('schedule')->label('Schedule')->icon('heroicon-o-clock')->color('gray')
                        ->visible(fn (Broadcast $record) => $record->isEditable())
                        ->form([Forms\Components\DateTimePicker::make('at')->label('Send at (Lagos time)')->seconds(false)->required()->minDate(now())
                            ->timezone((string) config('lotlink.timezone'))])
                        ->action(function (Broadcast $record, array $data, SendBroadcast $send): void {
                            $send->schedule($record, CarbonImmutable::parse($data['at']), self::admin());
                            Notification::make()->title('Scheduled')->success()->send();
                        }),
                    Tables\Actions\Action::make('unschedule')->label('Unschedule')->color('gray')
                        ->visible(fn (Broadcast $record) => $record->status === 'scheduled')
                        ->action(fn (Broadcast $record, SendBroadcast $send) => $send->unschedule($record, self::admin())),
                    Tables\Actions\Action::make('recipients')->label('Who got it')->icon('heroicon-o-users')->color('gray')
                        ->visible(fn (Broadcast $record) => $record->status === 'sent')
                        ->modalHeading(fn (Broadcast $record) => "Who got \"{$record->title}\"")
                        ->modalContent(fn (Broadcast $record) => new HtmlString(self::recipientTable($record)))
                        ->modalSubmitAction(false),
                    Tables\Actions\ReplicateAction::make()->label('Copy')->color('gray')
                        ->excludeAttributes(['ulid', 'status', 'scheduled_at', 'sent_at', 'recipients_count'])
                        ->beforeReplicaSaved(function (Broadcast $replica): void {
                            $replica->fill(['title' => 'Copy of '.$replica->title, 'status' => 'draft', 'created_by' => self::admin()->id]);
                        }),
                    Tables\Actions\DeleteAction::make()->visible(fn (Broadcast $record) => $record->isEditable()),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBroadcasts::route('/'),
            'create' => Pages\CreateBroadcast::route('/create'),
            'edit' => Pages\EditBroadcast::route('/{record}/edit'),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{states?: list<string>, plans?: list<int|string>, status?: string|null, verified?: string|null, activity?: string|null, managers?: bool}
     */
    public static function audience(array $raw): array
    {
        return [
            'states' => array_values(array_filter((array) ($raw['states'] ?? []))),
            'plans' => array_values(array_filter((array) ($raw['plans'] ?? []))),
            'status' => $raw['status'] ?? 'active',
            'verified' => $raw['verified'] ?? null,
            'activity' => $raw['activity'] ?? null,
            'managers' => (bool) ($raw['managers'] ?? false),
        ];
    }

    private static function opened(Broadcast $record): string
    {
        if ($record->recipients_count === 0) {
            return '—';
        }
        $opened = EngagementMessage::query()->where('broadcast_id', $record->id)->whereNotNull('clicked_at')->count();

        return number_format($opened).' ('.round(100 * $opened / $record->recipients_count).'%)';
    }

    private static function recipientTable(Broadcast $record): string
    {
        $rows = $record->messages()->with(['user', 'lot'])->latest('clicked_at')->limit(300)->get()
            ->map(fn (EngagementMessage $m) => '<tr><td style="padding:6px 8px">'.e($m->user->name ?? $m->user->phone ?? $m->user->email ?? '—').'</td><td style="padding:6px 8px">'
                .e($m->lot->name ?? '—').'</td><td style="padding:6px 8px">'.e($m->lot ? collect([$m->lot->city, $m->lot->state])->filter()->implode(', ') : '').'</td><td style="padding:6px 8px">'
                .e($m->clicked_at ? 'Opened '.$m->clicked_at->diffForHumans() : 'Not yet').'</td></tr>')->implode('');

        return '<table style="width:100%;font-size:14px;border-collapse:collapse"><thead><tr style="text-align:left;opacity:.7">'
            .'<th style="padding:6px 8px">Person</th><th style="padding:6px 8px">Seller</th><th style="padding:6px 8px">Where</th><th style="padding:6px 8px">Link</th></tr></thead><tbody>'
            .($rows ?: '<tr><td colspan="4" style="padding:6px 8px">Nobody yet.</td></tr>').'</tbody></table>';
    }

    private static function admin(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
