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
    $code = $this->sms->lastCodeFor(PHONE);

    expect($otp->phone)->toBe(PHONE)
        ->and($code)->not->toBeNull()
        ->and($otp->code_hash)->not->toBe($code);
});

it('rejects an invalid phone number', function () {
    $this->post(route('login.send'), ['phone' => '123'])->assertSessionHasErrors('phone');

    expect($this->sms->sent)->toBeEmpty();
});

it('signs in and creates a customer on first login', function () {
    requestCode();

    $this->post(route('login.check'), ['code' => $this->sms->lastCodeFor(PHONE)])
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
    $this->post(route('login.check'), ['code' => $this->sms->lastCodeFor(PHONE)]);

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
    $real = $this->sms->lastCodeFor(PHONE);
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

    $this->post(route('login.check'), ['code' => $this->sms->lastCodeFor(PHONE)])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('only accepts the newest code', function () {
    requestCode();
    $first = $this->sms->lastCodeFor(PHONE);
    $this->post(route('login.resend'));

    if ($first !== $this->sms->lastCodeFor(PHONE)) {
        $this->post(route('login.check'), ['code' => $first])->assertSessionHasErrors('code');
    }

    $this->post(route('login.check'), ['code' => $this->sms->lastCodeFor(PHONE)])->assertRedirect();
    $this->assertAuthenticated();
});

it('allows 3 codes per phone every 15 minutes', function () {
    foreach (range(1, 3) as $i) {
        requestCode();
    }

    $this->post(route('login.send'), ['phone' => PHONE])->assertSessionHasErrors('phone');
    expect($this->sms->sent)->toHaveCount(3);

    $this->travel(16)->minutes();
    requestCode();
    expect($this->sms->sent)->toHaveCount(4);
});

it('logs out', function () {
    $this->actingAs(User::factory()->create())->post(route('logout'))->assertRedirect(route('home'));
    $this->assertGuest();
});
