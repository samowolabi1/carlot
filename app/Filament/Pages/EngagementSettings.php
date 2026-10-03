<?php

namespace App\Filament\Pages;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminArea;
use App\Domain\Engagement\Actions\RunEngagementRules;
use App\Domain\Engagement\Models\EngagementMessage;
use App\Domain\Engagement\Support\EngagementRules;
use App\Filament\Pages\Concerns\AdminPage;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * The automated emails CarYard sends sellers (`EngagementRules`): on/off, when (threshold),
 * how often (cooldown), subject and opening line, the send hour, and last-30-day stats.
 * "Who gets it now" counts the sellers that qualify today; "Send me a test" emails the admin.
 *
 * @property Form $form
 */
class EngagementSettings extends Page implements HasForms
{
    use AdminPage;

    public static function adminAreas(): array
    {
        return [AdminArea::Settings];
    }

    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Engagement';

    protected static ?string $navigationLabel = 'Automated emails';

    protected static ?string $title = 'Automated emails to sellers';

    protected static ?string $slug = 'engagement/automated-emails';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.pages.engagement-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(EngagementRules::current());
    }

    public function form(Form $form): Form
    {
        $sections = collect(EngagementRules::RULES)->map(fn (array $rule, string $key) => Forms\Components\Section::make($rule['label'])
            ->description($rule['description'].' '.self::stats($key))
            ->collapsible()
            ->columns(3)
            ->headerActions([
                FormAction::make("preview_{$key}")->label('Who gets it now')->link()
                    ->action(fn (RunEngagementRules $rules) => Notification::make()->title($rules->preview($key).' sellers qualify today')
                        ->body('Counted with the saved settings, after each seller\'s cooldown.')->info()->send()),
                FormAction::make("test_{$key}")->label('Send me a test')->link()
                    ->action(function (RunEngagementRules $rules) use ($key): void {
                        /** @var User $admin */
                        $admin = Auth::user();
                        $rules->test($key, $admin);
                        Notification::make()->title('Test sent to '.($admin->email ?? 'you'))->success()->send();
                    }),
            ])
            ->schema([
                Forms\Components\Toggle::make("rules.{$key}.enabled")->label('On')->inline(false),
                Forms\Components\TextInput::make("rules.{$key}.threshold")->label($rule['unit'] === 'hours' ? 'Waiting at least (hours)' : 'After (days)')
                    ->numeric()->integer()->minValue(1)->maxValue(365)->required(),
                Forms\Components\TextInput::make("rules.{$key}.cooldown")->label('At most every (days)')->numeric()->integer()->minValue(1)->maxValue(365)->required(),
                Forms\Components\TextInput::make("rules.{$key}.subject")->label('Subject')->required()->maxLength(120)->columnSpanFull(),
                Forms\Components\Textarea::make("rules.{$key}.intro")->label('Opening line')->required()->maxLength(600)->rows(2)->columnSpanFull()
                    ->helperText('Placeholders: {name} (owner\'s first name), {lot}, {count}. The details (cars, chats…) are listed under it.'),
            ]))->values()->all();

        return $form->statePath('data')->schema([
            Forms\Components\Section::make('When they go')->schema([
                Forms\Components\Select::make('hour')->label('Send hour (each seller\'s local time)')->required()
                    ->options(collect(range(6, 20))->mapWithKeys(fn (int $h) => [$h => sprintf('%02d:00', $h)])),
            ]),
            ...$sections,
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $values = ['hour' => (int) $state['hour'], 'rules' => []];
        foreach (array_keys(EngagementRules::RULES) as $key) {
            $r = (array) ($state['rules'][$key] ?? []);
            $values['rules'][$key] = [
                'enabled' => (bool) ($r['enabled'] ?? false),
                'threshold' => (int) $r['threshold'],
                'cooldown' => (int) $r['cooldown'],
                'subject' => trim((string) $r['subject']),
                'intro' => trim((string) $r['intro']),
            ];
        }

        /** @var User $admin */
        $admin = Auth::user();
        EngagementRules::save($values, $admin);
        Notification::make()->title('Automated emails saved')->success()->send();
        $this->form->fill(EngagementRules::current());
    }

    /** @var array<string, array{sent: int, opened: int}>|null all rules' 30-day figures, in one query */
    private static ?array $stats = null;

    /** "Last 30 days: 42 sent, 17 opened (40%)." */
    private static function stats(string $rule): string
    {
        self::$stats ??= EngagementMessage::query()->whereNotNull('rule')->where('sent_at', '>=', now()->subDays(30))
            ->groupBy('rule')->selectRaw('rule, count(*) as sent, count(clicked_at) as opened')->get()
            ->mapWithKeys(fn ($row) => [(string) $row->getAttribute('rule') => ['sent' => (int) $row->getAttribute('sent'), 'opened' => (int) $row->getAttribute('opened')]])->all();
        $total = self::$stats[$rule]['sent'] ?? 0;
        $opened = self::$stats[$rule]['opened'] ?? 0;

        return $total === 0 ? 'None sent in the last 30 days.' : "Last 30 days: {$total} sent, {$opened} opened (".round(100 * $opened / $total).'%).';
    }
}
