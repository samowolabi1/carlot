<?php

namespace App\Filament\Pages;

use App\Domain\Accounts\Models\User;
use App\Domain\Accounts\Support\SignInOptions;
use App\Domain\Admin\AdminArea;
use App\Filament\Pages\Concerns\AdminPage;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * How people get their sign-in codes: email always; WhatsApp only while this is on (each code is a paid Meta message).
 * Passwords and Google work either way. See SignInOptions.
 *
 * @property Form $form
 */
class SignInSettings extends Page implements HasForms
{
    use AdminPage;
    use InteractsWithForms;

    public static function adminAreas(): array
    {
        return [AdminArea::Settings];
    }

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Sign-in';

    protected static ?string $title = 'Sign-in';

    protected static ?string $slug = 'settings/sign-in';

    protected static string $view = 'filament.pages.payment-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['whatsapp' => SignInOptions::whatsappCodes()]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Sign-in codes')
                ->description('People sign in, and create their account, with a 6-digit code. Email codes are free and always on.')
                ->schema([
                    Forms\Components\Toggle::make('whatsapp')->label('Also send codes by WhatsApp')
                        ->helperText('Each code is a paid WhatsApp message from Meta. Off: the sign-in page asks for an email address only.'),
                    Forms\Components\Placeholder::make('note')->hiddenLabel()->content(new HtmlString(self::note())),
                ]),
        ]);
    }

    public function save(): void
    {
        $on = (bool) $this->form->getState()['whatsapp'];

        /** @var User $admin */
        $admin = auth()->user();
        SignInOptions::setWhatsappCodes($on, $admin);

        Notification::make()
            ->title($on ? 'Codes go by WhatsApp or email' : 'Codes go by email only')
            ->body($on && config('lotlink.whatsapp_driver') !== 'meta' ? 'WhatsApp isn\'t connected yet (WHATSAPP_DRIVER=log), so WhatsApp codes are only written to the log. Connect it before telling people.' : null)
            ->success()->send();
    }

    private static function note(): string
    {
        $connected = config('lotlink.whatsapp_driver') === 'meta'
            ? 'WhatsApp is connected (Meta Cloud API).'
            : '<strong>WhatsApp isn\'t connected yet</strong> (WHATSAPP_DRIVER=log): keep this off until the Meta account, number and the <code>login_code</code> template are approved.';
        $phoneOnly = User::query()->whereNotNull('phone')->whereNull('email')->whereNull('password')->whereNull('google_id')->count();

        return '<div style="display:grid;gap:8px;font-size:14px"><div>'.$connected.'</div>'
            .'<div>While it\'s off, people who signed up with only a WhatsApp number can\'t get a code: '
            .$phoneOnly.' '.str('account')->plural($phoneOnly).' have no email, password or Google. Other WhatsApp messages (bookings, receipts, alerts) are not affected by this switch.</div></div>';
    }
}
