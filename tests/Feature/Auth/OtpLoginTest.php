<?php

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\OtpCode;
use App\Domain\Accounts\Models\User;

const PHONE = '+2348031234412';

function requestCode(): void
{
    test()->post(route('login.send'), ['phone' => '0803 123 4412'])->assertRedirect(route('login.verify'));
}

it('sends a hashed code to the normalised number', function () {
    requestCode();

    $otp = OtpCode::sole();
    $code = $this->lastCode(PHONE);
    expect($this->whatsapp->to(PHONE, 'login_code'))->toHaveCount(1);

    expect($otp->phone)->toBe(PHONE)
        ->and($code)->not->toBeNull()
        ->and($otp->code_hash)->not->toBe($code);
});

it('rejects an invalid phone number', function () {
    $this->post(route('login.send'), ['phone' => '123'])->assertSessionHasErrors('phone');

    expect($this->sms->sent)->toBeEmpty()->and($this->whatsapp->sent)->toBeEmpty();
});

it('signs in and creates a customer on first login', function () {
    requestCode();

    $this->post(route('login.check'), ['code' => $this->lastCode(PHONE)])
        ->assertRedirect(route('home'));

    $user = User::sole();
    expect($user->phone)->toBe(PHONE)
        ->and($user->role)->toBe(UserRole::Customer)
        ->and($user->phone_verified_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user);
});

it('signs an existing user back in without creating a duplicate', function () {
    $existing = User::factory()->create(['phone' => PHONE]);

    requestCode();
    $this->post(route('login.check'), ['code' => $this->lastCode(PHONE)]);

    $this->assertAuthenticatedAs($existing);
    expect(User::count())->toBe(1);
});

it('asks new users for their name before the dealer area', function () {
    $user = User::factory()->unnamed()->create();

    $this->actingAs($user)->get(route('dealer.home'))->assertRedirect(route('profile.name'));
    $this->actingAs($user)->put(route('profile.name.update'), ['name' => 'Chioma Okafor'])->assertRedirect();

    expect($user->fresh()->name)->toBe('Chioma Okafor');
});

it('rejects a wrong code and locks the code after 5 attempts', function () {
    requestCode();
    $real = $this->lastCode(PHONE);
    $wrong = $real === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.check'), ['code' => $wrong])->assertSessionHasErrors('code');
    }

    // Even the right code no longer works.
    $this->post(route('login.check'), ['code' => $real])->assertSessionHasErrors('code');
    $this->assertGuest();
    expect(OtpCode::sole()->attempts)->toBe(5);
});

it('rejects an expired code', function () {
    requestCode();
    $this->travel(6)->minutes();

    $this->post(route('login.check'), ['code' => $this->lastCode(PHONE)])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('only accepts the newest code', function () {
    requestCode();
    $first = $this->lastCode(PHONE);
    $this->post(route('login.resend'));

    if ($first !== $this->lastCode(PHONE)) {
        $this->post(route('login.check'), ['code' => $first])->assertSessionHasErrors('code');
    }

    $this->post(route('login.check'), ['code' => $this->lastCode(PHONE)])->assertRedirect();
    $this->assertAuthenticated();
});

it('allows 3 codes per phone every 15 minutes', function () {
    foreach (range(1, 3) as $i) {
        requestCode();
    }

    $this->post(route('login.send'), ['phone' => PHONE])->assertSessionHasErrors('phone');
    expect($this->whatsapp->sent)->toHaveCount(3);

    $this->travel(16)->minutes();
    requestCode();
    expect($this->whatsapp->sent)->toHaveCount(4);
});

it('logs out', function () {
    $this->actingAs(User::factory()->create())->post(route('logout'))->assertRedirect(route('home'));
    $this->assertGuest();
});

it('never falls back to paid SMS unless it is switched on', function () {
    $this->whatsapp->failing = true;

    $this->post(route('login.send'), ['phone' => '0803 123 4412'])->assertSessionHasErrors(['phone' => 'We couldn\'t reach that number on WhatsApp. Check the number, or sign in with your email instead.']);
    expect($this->sms->sent)->toBe([]);

    config(['lotlink.otp.sms_fallback' => true]);
    requestCode();
    expect($this->sms->lastCodeFor(PHONE))->not->toBeNull();
    $this->get(route('login.verify'))->assertInertia(fn ($page) => $page->where('channel', 'sms'));
});

it('sends the code by SMS when asked, only if SMS is switched on', function () {
    requestCode();
    $this->get(route('login.verify'))->assertInertia(fn ($page) => $page->where('channel', 'whatsapp')->where('smsAvailable', false));
    $this->post(route('login.resend'), ['channel' => 'sms'])->assertSessionHasErrors('code');
    expect($this->sms->sent)->toBe([]);

    config(['lotlink.otp.sms_fallback' => true]);
    $this->travel(1)->minute();
    $this->post(route('login.resend'), ['channel' => 'sms']);

    $code = $this->sms->lastCodeFor(PHONE);
    expect($code)->not->toBeNull();
    $this->post(route('login.check'), ['code' => $code])->assertRedirect();
    $this->assertAuthenticated();
});
