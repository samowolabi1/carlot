<?php

namespace App\Filament\Resources;

use App\Domain\Accounts\Models\User;
use App\Domain\Messaging\MessageCatalogue;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Filament\Resources\MessageTemplateResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * WhatsApp/SMS messages (TDD notification matrix). WhatsApp wording lives in Meta's approved
 * templates, so here admins pick which approved template and language to use, reword the SMS
 * fallback, or switch a message off. See `MessageCatalogue`.
 */
class MessageTemplateResource extends Resource
{
    protected static ?string $model = MessageTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Message templates';

    protected static ?string $recordTitleAttribute = 'key';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(fn (?MessageTemplate $record) => $record ? self::info($record->key)['label'] : 'Message')
                ->description(fn (?MessageTemplate $record) => $record ? self::summary($record->key) : null)
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('whatsapp_template')->label('WhatsApp template name')->required()->maxLength(64)
                        ->regex('/^[a-z0-9_]+$/')
                        ->helperText('The approved template in Meta Business Manager. To change the WhatsApp wording, get a new version approved (same variables and button) and enter its name here.'),
                    Forms\Components\TextInput::make('language')->label('Template language')->required()->maxLength(10)
                        ->regex('/^[a-z]{2,3}(_[A-Z]{2})?$/')->helperText('Meta language code, e.g. en or en_GB.'),
                    Forms\Components\Textarea::make('sms_text')->label('SMS wording')->rows(4)->maxLength(640)->columnSpanFull()->live(debounce: 400)
                        ->helperText(fn (?MessageTemplate $record) => $record ? self::placeholderHelp($record->key) : null)
                        ->rule(fn (?MessageTemplate $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                            foreach (MessageCatalogue::problems((string) $record?->key, is_string($value) ? $value : null) as $problem) {
                                $fail($problem);
                            }
                        }),
                    Forms\Components\Placeholder::make('preview')->label('SMS preview (sample values)')->columnSpanFull()
                        ->visible(fn (Get $get) => filled($get('sms_text')))
                        ->content(function (Get $get, ?MessageTemplate $record): HtmlString {
                            $text = MessageCatalogue::render((string) $get('sms_text'), MessageCatalogue::sample((string) $record?->key));
                            $segments = max(1, (int) ceil(mb_strlen($text) / (mb_strlen($text) > 160 ? 153 : 160)));

                            return new HtmlString(e($text).'<br><small>'.mb_strlen($text).' characters · '.$segments.' SMS '.($segments === 1 ? 'part' : 'parts').'</small>');
                        }),
                    Forms\Components\Toggle::make('enabled')->label('Send this message')
                        ->disabled(fn (?MessageTemplate $record) => $record && MessageCatalogue::required($record->key))
                        ->helperText(fn (?MessageTemplate $record) => $record && MessageCatalogue::required($record->key)
                            ? 'Always on: people need it to sign in or join a team.'
                            : 'Off stops both the WhatsApp and SMS version. In-app notifications still show.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('label')->label('Message')->state(fn (MessageTemplate $record) => self::info($record->key)['label'])
                    ->description(fn (MessageTemplate $record) => $record->key),
                Tables\Columns\TextColumn::make('category')->state(fn (MessageTemplate $record) => self::info($record->key)['category'])->badge()
                    ->color(fn (string $state) => match ($state) {
                        'Marketing' => 'warning',
                        'Authentication' => 'info',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('whatsapp_template')->label('WhatsApp template')
                    ->description(fn (MessageTemplate $record) => $record->whatsapp_template !== $record->key ? 'Replaced version' : null),
                Tables\Columns\TextColumn::make('language'),
                Tables\Columns\IconColumn::make('sms_text')->label('Custom SMS')->boolean()->state(fn (MessageTemplate $record) => filled($record->sms_text)),
                Tables\Columns\ToggleColumn::make('enabled')->label('On')
                    ->disabled(fn (MessageTemplate $record) => MessageCatalogue::required($record->key))
                    ->updateStateUsing(function (MessageTemplate $record, bool $state): bool {
                        /** @var User $user */
                        $user = auth()->user();
                        MessageCatalogue::save($record, [...$record->only(['whatsapp_template', 'language', 'sms_text']), 'enabled' => $state], $user);

                        return $record->enabled;
                    }),
                Tables\Columns\TextColumn::make('updated_at')->label('Changed')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('enabled')->label('On'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMessageTemplates::route('/'),
            'edit' => Pages\EditMessageTemplate::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false; // the list is the catalogue in code
    }

    /** @return array{label: string, category: string, variables: list<string>, link: string|null, required?: bool} */
    public static function info(string $key): array
    {
        return MessageCatalogue::TEMPLATES[$key] ?? ['label' => $key, 'category' => 'Retired', 'variables' => [], 'link' => null];
    }

    private static function summary(string $key): string
    {
        $info = self::info($key);
        $variables = collect($info['variables'])->map(fn (string $v, int $i) => '{{'.($i + 1)."}} {$v}")->implode(', ');

        return "{$info['category']} template. Variables: {$variables}".($info['link'] ? ". Button: {$info['link']}." : '.');
    }

    private static function placeholderHelp(string $key): string
    {
        $info = self::info($key);
        $list = collect($info['variables'])->map(fn (string $v, int $i) => '{'.($i + 1)."} = {$v}")->implode(', ');

        return "Leave empty for the built-in wording. Placeholders: {$list}".($info['link'] ? ", {link} = {$info['link']} URL" : '')
            .'. Keep it under 160 characters for a single SMS.';
    }
}
