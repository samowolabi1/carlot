<?php

namespace App\Filament\Resources\MessageTemplateResource\Pages;

use App\Domain\Accounts\Models\User;
use App\Domain\Messaging\MessageCatalogue;
use App\Domain\Messaging\Messenger;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Domain\Support\PhoneNumber;
use App\Filament\Resources\MessageTemplateResource;
use App\Rules\FieldPattern;
use App\Rules\PhoneNumberRule;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class EditMessageTemplate extends EditRecord
{
    protected static string $resource = MessageTemplateResource::class;

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var MessageTemplate $record */
        /** @var User $user */
        $user = auth()->user();
        MessageCatalogue::save($record, [
            'whatsapp_template' => (string) $data['whatsapp_template'],
            'language' => (string) $data['language'],
            'sms_text' => filled($data['sms_text'] ?? null) ? trim((string) $data['sms_text']) : null,
            'enabled' => (bool) ($data['enabled'] ?? true),
        ], $user);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')->label('Send a test')->icon('heroicon-o-paper-airplane')->color('gray')
                ->modalDescription('Sends this message with sample values, as saved (save your changes first). WhatsApp first, then SMS.')
                ->form([
                    Forms\Components\TextInput::make('phone')->label('Phone number')->tel()->required()->maxLength(20)->rules([new FieldPattern('phone'), new PhoneNumberRule])->placeholder('0803 123 4567'),
                    Forms\Components\Toggle::make('sms')->label('Send as SMS only'),
                ])
                ->action(function (array $data, Messenger $messenger): void {
                    /** @var MessageTemplate $template */
                    $template = $this->getRecord();
                    $phone = PhoneNumber::tryNormalize((string) $data['phone']);

                    if ($phone === null) {
                        Notification::make()->title('That phone number doesn\'t look right')->danger()->send();

                        return;
                    }

                    $sample = MessageCatalogue::sample($template->key);
                    $label = MessageTemplateResource::info($template->key)['label'];
                    $message = $sample->with($sample->template, null, "LotLink test: {$label} (".implode(', ', $sample->params).').');

                    try {
                        $channel = $messenger->send($phone, $message, preferWhatsApp: ! ($data['sms'] ?? false));
                        Notification::make()->title($channel === 'off' ? 'This message is switched off' : "Test sent by {$channel}")->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('Couldn\'t send the test')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
