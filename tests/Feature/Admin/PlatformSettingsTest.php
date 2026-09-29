<?php

use App\Domain\Accounts\Actions\SendOtp;
use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Finance\Support\FinanceCalculator;
use App\Domain\Finance\Support\FinanceRates;
use App\Domain\Messaging\Message;
use App\Domain\Messaging\MessageCatalogue;
use App\Domain\Messaging\Messenger;
use App\Domain\Messaging\Models\MessageTemplate;
use App\Filament\Pages\FinanceSettings;
use App\Filament\Resources\MessageTemplateResource\Pages\EditMessageTemplate;
use App\Filament\Resources\MessageTemplateResource\Pages\ListMessageTemplates;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

afterEach(fn () => FinanceRates::flush());

it('keeps the settings pages for admins only', function () {
    $this->get('/admin/settings/finance')->assertRedirect();
    $this->actingAs(User::factory()->create())->get('/admin/settings/finance')->assertForbidden();
    $this->actingAs(User::factory()->create())->get('/admin/message-templates')->assertForbidden();

    $this->actingAs($this->admin)->get('/admin/settings/finance')->assertOk()->assertSee('Finance rates');
    $this->actingAs($this->admin)->get('/admin/message-templates')->assertOk()->assertSee('Booking confirmed');
});

it('lets an admin change the finance rates buyers see', function () {
    $this->actingAs($this->admin);
    $before = FinanceCalculator::fromPrice(10_000_000)['monthly'];

    Livewire::test(FinanceSettings::class)
        ->assertFormSet(['affordability_percent' => 35.0, 'interest_rate' => 24.0, 'tenor_months' => 36])
        ->fillForm([
            'affordability_percent' => 40,
            'interest_rate' => 18.5,
            'deposit_percent' => 20,
            'tenors' => [12, 24, 36, 48, 60],
            'tenor_months' => 48,
            'fuel_price' => 1250,
            'servicing' => [['up_to' => 99, 'value' => 700000], ['up_to' => 4, 'value' => 300000]],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(config('lotlink.finance'))
        ->affordability_ratio->toBe(0.4)
        ->interest_rate->toBe(18.5)
        ->tenor_months->toBe(48)
        ->tenors->toBe([12, 24, 36, 48, 60])
        ->fuel_price->toBe(1250)
        ->servicing->toBe([4 => 300000, 99 => 700000])
        ->and(FinanceCalculator::fromPrice(10_000_000))->deposit_percent->toBe(20)->months->toBe(48)
        ->and(FinanceCalculator::fromPrice(10_000_000)['monthly'])->not->toBe($before)
        ->and(AuditLog::where('action', 'admin.finance_rates_changed')->exists())->toBeTrue();

    // A fresh process (the next request or a queue worker) picks the saved rates up.
    config(['lotlink.finance' => FinanceRates::defaults()]);
    FinanceRates::apply();
    expect(config('lotlink.finance.interest_rate'))->toBe(18.5);

    // Buyers get them on the budget page.
    $this->get(route('budget'))->assertInertia(fn ($page) => $page->where('finance.interest_rate', 18.5)->where('finance.tenor_months', 48));
});

it('refuses a default loan length buyers cannot pick', function () {
    $this->actingAs($this->admin);

    Livewire::test(FinanceSettings::class)
        ->fillForm(['tenors' => [12, 24], 'tenor_months' => 36])
        ->call('save')
        ->assertHasFormErrors(['tenor_months']);

    expect(FinanceRates::overrides())->toBe([]);
});

it('resets the finance rates to the defaults', function () {
    $this->actingAs($this->admin);
    FinanceRates::save([...FinanceRates::defaults(), 'interest_rate' => 30.0], $this->admin);
    expect(config('lotlink.finance.interest_rate'))->toBe(30.0);

    Livewire::test(FinanceSettings::class)->callAction('reset');

    expect(config('lotlink.finance.interest_rate'))->toBe(24.0)
        ->and(FinanceRates::overrides())->toBe([]);
});

it('lists every message the app sends', function () {
    $this->actingAs($this->admin);

    Livewire::test(ListMessageTemplates::class)->assertCountTableRecords(count(MessageCatalogue::TEMPLATES));

    expect(MessageTemplate::pluck('key')->sort()->values()->all())->toBe(collect(MessageCatalogue::TEMPLATES)->keys()->sort()->values()->all());
});

it('sends the admin\'s WhatsApp template version, language and SMS wording', function () {
    MessageCatalogue::sync();
    $template = MessageTemplate::where('key', 'booking_confirmed')->sole();
    $this->actingAs($this->admin);

    Livewire::test(EditMessageTemplate::class, ['record' => $template->getRouteKey()])
        ->fillForm([
            'whatsapp_template' => 'booking_confirmed_v2',
            'language' => 'en_GB',
            'sms_text' => 'Hi {1}, your {2} at {3} is on for {4}. Manage it: {link}',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $message = new Message('booking_confirmed', ['Ada', 'viewing', 'Prime Motors', 'Sat 11:00'], 'Built-in text', 'b/abc');
    $messenger = app(Messenger::class);

    $messenger->send('+2348030000001', $message);
    expect($this->whatsapp->sent[0]['message'])->template->toBe('booking_confirmed_v2')->language->toBe('en_GB');

    $messenger->send('+2348030000001', $message, preferWhatsApp: false);
    expect($this->sms->sent[0]['message'])->toBe('Hi Ada, your viewing at Prime Motors is on for Sat 11:00. Manage it: '.url('b/abc'))
        ->and(AuditLog::where('action', 'admin.message_template_changed')->exists())->toBeTrue();
});

it('checks SMS placeholders before saving', function (string $key, string $sms, string $error) {
    MessageCatalogue::sync();
    $this->actingAs($this->admin);

    Livewire::test(EditMessageTemplate::class, ['record' => MessageTemplate::where('key', $key)->sole()->getRouteKey()])
        ->fillForm(['sms_text' => $sms])
        ->call('save')
        ->assertHasFormErrors(['sms_text']);

    expect(MessageCatalogue::problems($key, $sms))->toContain($error);
})->with([
    ['booking_confirmed', 'Hi {1}, see {9}: {link}', "{9} isn't available here. Use {1}, {2}, {3}, {4}, {link}."],
    ['booking_confirmed', 'Hi {1}, booked.', 'Include {link} so people can manage booking from the SMS.'],
    ['login_code', 'Your LotLink code is ready.', 'The sign-in code text must include the code: {1}.'],
]);

it('stops a message an admin switched off, but never sign-in codes', function () {
    MessageCatalogue::sync();
    $this->actingAs($this->admin);

    Livewire::test(ListMessageTemplates::class)
        ->call('updateTableColumnState', 'enabled', (string) MessageTemplate::where('key', 'price_drop')->value('id'), false);
    expect(MessageTemplate::where('key', 'price_drop')->value('enabled'))->toBeFalse();

    $messenger = app(Messenger::class);
    expect($messenger->send('+2348030000001', new Message('price_drop', ['Camry', '₦9m', '₦1m', 'Prime'], 'Price drop')))->toBe('off')
        ->and($this->whatsapp->sent)->toBe([])->and($this->sms->sent)->toBe([]);

    MessageCatalogue::save(MessageTemplate::where('key', 'login_code')->sole(), ['whatsapp_template' => 'login_code', 'language' => 'en', 'sms_text' => null, 'enabled' => false], $this->admin);
    expect(MessageTemplate::where('key', 'login_code')->value('enabled'))->toBeTrue();

    app(SendOtp::class)->run('+2348030000002');
    expect($this->lastCode('+2348030000002'))->not->toBeNull();
});

it('sends a test message with sample values', function () {
    MessageCatalogue::sync();
    $this->actingAs($this->admin);

    Livewire::test(EditMessageTemplate::class, ['record' => MessageTemplate::where('key', 'review_invite')->sole()->getRouteKey()])
        ->callAction('test', ['phone' => '0803 123 4567', 'sms' => false])
        ->assertHasNoActionErrors();

    expect($this->whatsapp->to('+2348031234567', 'review_invite'))->toHaveCount(1)
        ->and($this->whatsapp->to('+2348031234567', 'review_invite')[0]->params)->toBe(['Prime Motors', 'What (visit type and car)']);
});

it('sends messages as the code wrote them when nothing was changed', function () {
    $message = new Message('order_update', ['Ada', 'Camry', 'Prime', 'Ready'], 'Built-in', 'o/1');

    expect(MessageCatalogue::apply($message))->toBe($message);

    MessageCatalogue::sync();
    expect(MessageCatalogue::apply($message))->template->toBe('order_update')->text->toBe('Built-in');
});
