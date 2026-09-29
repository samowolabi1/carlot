<?php

namespace App\Filament\Pages;

use App\Domain\Accounts\Models\User;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Gateways\PaymentGateways;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Lots\Models\Plan;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * Which provider takes lots' payments to LotLink (plans, spotlights, adverts): Paystack or
 * Flutterwave. Switching only affects new payments; each subscription keeps renewing, refunding
 * and cancelling through the provider it started on.
 *
 * @property Form $form
 */
class PaymentSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Payments';

    protected static ?string $title = 'Payment provider';

    protected static ?string $slug = 'settings/payments';

    protected static string $view = 'filament.pages.payment-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['provider' => PaymentGateways::activeProvider()]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make('New payments go through')
                ->description('Plans, renewals of new subscriptions, spotlights and adverts. Lots pay by card, bank transfer or USSD on the provider\'s page.')
                ->schema([
                    Forms\Components\Radio::make('provider')->hiddenLabel()->required()
                        ->options(PaymentGateways::PROVIDERS)
                        ->descriptions(collect(PaymentGateways::PROVIDERS)->mapWithKeys(fn (string $label, string $p) => [$p => self::status($p)])->all())
                        ->disableOptionWhen(fn (string $value) => ! PaymentGateways::configured($value)),
                    Forms\Components\Placeholder::make('note')->hiddenLabel()->content(new HtmlString(
                        'Switching doesn\'t move anyone: existing subscriptions keep renewing with the provider they started on until the lot changes plan. '
                        .(PaymentGateways::live() ? '' : '<strong>Test mode</strong> (PAYMENT_DRIVER=sandbox): both use the test checkout.'))),
                ]),
            Forms\Components\Section::make('Set up at the providers')->collapsible()->collapsed()->schema([
                Forms\Components\Placeholder::make('setup')->hiddenLabel()->content(new HtmlString(self::setup())),
            ]),
        ]);
    }

    public function save(): void
    {
        $provider = (string) $this->form->getState()['provider'];
        if (! PaymentGateways::configured($provider)) {
            Notification::make()->title(PaymentGateways::PROVIDERS[$provider].' isn\'t set up yet')->body('Add its keys to .env first (see "Set up at the providers").')->danger()->send();

            return;
        }

        /** @var User $admin */
        $admin = auth()->user();
        PaymentGateways::choose($provider, $admin);

        $missing = Plan::query()->where('self_serve', true)->where('price', '>', 0)->get()->filter(fn (Plan $p) => $p->codeFor($provider) === null)->pluck('name');
        $notice = Notification::make()->title('New payments go through '.PaymentGateways::PROVIDERS[$provider])->success();
        if ($missing->isNotEmpty()) {
            $notice->body('These plans have no '.PaymentGateways::PROVIDERS[$provider].' plan yet, so they won\'t renew automatically: '.$missing->implode(', ').'. Use "Create on '.PaymentGateways::PROVIDERS[$provider].'" in Plans.')->persistent();
        }
        $notice->send();
    }

    private static function status(string $provider): string
    {
        $subs = Subscription::withoutGlobalScopes()->where('provider', $provider)->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])->count();

        $state = match (true) {
            ! PaymentGateways::live() => 'Test mode (no keys needed). ',
            PaymentGateways::configured($provider) => 'Keys set. ',
            default => 'Not set up: add its keys to .env. ',
        };

        return $state
            ."{$subs} ".str('subscription')->plural($subs).' renewing through it.';
    }

    private static function setup(): string
    {
        $paystack = e(route('webhooks.paystack'));
        $flutterwave = e(route('webhooks.flutterwave'));

        return <<<HTML
            <div style="display:grid;gap:12px;font-size:14px">
            <div><strong>Paystack</strong>: PAYSTACK_SECRET_KEY and PAYSTACK_PUBLIC_KEY in .env. Dashboard → Settings → API Keys &amp; Webhooks → webhook URL <code>{$paystack}</code>.</div>
            <div><strong>Flutterwave</strong>: FLUTTERWAVE_SECRET_KEY and FLUTTERWAVE_PUBLIC_KEY in .env. Dashboard → Settings → Webhooks → URL <code>{$flutterwave}</code>, tick "Receive webhook for failed payment" and subscription events, and set a <em>secret hash</em>; put the same value in FLUTTERWAVE_SECRET_HASH.</div>
            <div>Then PAYMENT_DRIVER=live and <code>php artisan config:clear</code>.</div>
            </div>
            HTML;
    }
}
