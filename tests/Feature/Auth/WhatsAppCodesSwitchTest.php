<?php

use App\Domain\Accounts\Models\OtpCode;
use App\Domain\Accounts\Models\User;
use App\Domain\Accounts\Support\SignInOptions;
use App\Domain\Audit\AuditLog;
use App\Filament\Pages\SignInSettings;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

/* Sign-in codes by WhatsApp cost money per message: off by default, switched by an admin (/admin → Settings → Sign-in). */

beforeEach(fn () => config(['lotlink.otp.whatsapp' => false]));

it('is off by default and offers email only on the sign-in pages', function () {
    expect(SignInOptions::whatsappCodes())->toBeFalse();

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('whatsapp', false)->where('method', 'email'));
    $this->get(route('login', ['method' => 'password']))->assertInertia(fn (Assert $page) => $page->where('method', 'password'));
});

it('sends no WhatsApp code while it is off, on the web and in the API', function () {
    $this->post(route('login.send'), ['phone' => '0803 123 4412'])->assertSessionHasErrors(['phone' => 'Codes are sent by email for now. Sign in with your email address, or with a password if you added one.']);
    $this->postJson('/api/v1/auth/code', ['phone' => '0803 123 4412'])->assertUnprocessable()->assertJsonValidationErrors('phone');

    expect($this->whatsapp->sent)->toBeEmpty()->and($this->sms->sent)->toBeEmpty()->and(OtpCode::count())->toBe(0);
});

it('still signs people up by email while it is off', function () {
    Mail::fake();
    $this->post(route('login.send'), ['method' => 'email', 'email' => 'ada@example.com'])->assertRedirect(route('login.verify'));
    $this->get(route('login.verify'))->assertInertia(fn (Assert $page) => $page->component('Auth/Verify')->where('whatsapp', false)->where('method', 'email'));

    expect(OtpCode::where('email', 'ada@example.com')->count())->toBe(1);
});

it('lets an admin switch WhatsApp codes on and off, audit-logged', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    Livewire::test(SignInSettings::class)->assertFormSet(['whatsapp' => false])
        ->fillForm(['whatsapp' => true])->call('save')->assertHasNoFormErrors();

    expect(SignInOptions::whatsappCodes())->toBeTrue()
        ->and(AuditLog::where('action', 'admin.whatsapp_codes_changed')->count())->toBe(1);

    auth()->logout();
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('whatsapp', true)->where('method', 'whatsapp'));
    $this->post(route('login.send'), ['phone' => '0803 123 4412'])->assertRedirect(route('login.verify'));
    expect($this->whatsapp->to('+2348031234412', 'login_code'))->toHaveCount(1);

    SignInOptions::setWhatsappCodes(false, $admin);
    expect(SignInOptions::whatsappCodes())->toBeFalse();
});

it('keeps the sign-in settings page for admins only', function () {
    $this->actingAs(User::factory()->create())->get('/admin/settings/sign-in')->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get('/admin/settings/sign-in')->assertOk()->assertSee('Also send codes by WhatsApp');
});

it('onboards sellers by email while WhatsApp codes are off', function () {
    $admin = User::factory()->admin()->create();

    expect(fn () => app(App\Domain\Lots\Actions\OnboardLot::class)->run($admin, [
        'owner_name' => 'Ada Obi', 'sign_in' => 'whatsapp', 'phone' => '08035551313', 'lot_name' => 'Ada Cars', 'state' => 'FCT', 'city' => 'Garki',
    ]))->toThrow(Illuminate\Validation\ValidationException::class, 'Sign-in codes go by email for now');
});
