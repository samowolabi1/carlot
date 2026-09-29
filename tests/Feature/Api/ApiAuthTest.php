<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Accounts\Notifications\ResetPasswordLink;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;

function signOutGuards(): void
{
    Auth::forgetGuards();
}

it('signs up with a WhatsApp code and gets a token for the device', function () {
    $this->postJson(route('api.auth.code'), ['phone' => '0803 123 4412'])
        ->assertAccepted()->assertJson(['channel' => 'whatsapp'])->assertJsonPath('sent_to', '+234 803 *** 4412');

    $this->postJson(route('api.auth.token'), ['phone' => '0803 123 4412', 'code' => '000000', 'device_name' => 'Pixel 8'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');

    $response = $this->postJson(route('api.auth.token'), ['phone' => '0803 123 4412', 'code' => $this->lastCode('+2348031234412'), 'device_name' => 'Pixel 8'])
        ->assertCreated()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('data.phone', '+2348031234412')
        ->assertJsonPath('needs_name', true);

    $token = $response->json('token');
    expect(PersonalAccessToken::sole())->name->toBe('Pixel 8')
        ->and($response->json('expires_at'))->toStartWith(now()->addDays(90)->toDateString());

    $this->withToken($token)->patchJson(route('api.me.update'), ['name' => 'Ada Obi'])->assertOk()->assertJsonPath('data.name', 'Ada Obi');
    $this->withToken($token)->getJson(route('api.me'))->assertOk()->assertJsonPath('data.name', 'Ada Obi')->assertJsonMissingPath('data.id');

    $this->withToken($token)->deleteJson(route('api.auth.logout'))->assertNoContent();
    signOutGuards();
    $this->withToken($token)->getJson(route('api.me'))->assertUnauthorized();
});

it('signs in by email code or password, with the same lockout as the web', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'lagos2026cars']);

    $this->postJson(route('api.auth.password'), ['login' => 'ada@example.com', 'password' => 'lagos2026cars', 'device_name' => 'iPhone'])
        ->assertCreated()->assertJsonPath('data.ulid', $user->ulid)->assertJsonPath('data.has_password', true);

    foreach (range(1, 5) as $_) {
        $this->postJson(route('api.auth.password'), ['login' => 'ada@example.com', 'password' => 'guess1234', 'device_name' => 'x'])->assertUnprocessable();
    }
    $this->postJson(route('api.auth.password'), ['login' => 'ada@example.com', 'password' => 'lagos2026cars', 'device_name' => 'x'])
        ->assertUnprocessable()->assertJsonPath('errors.login.0', fn (string $m) => str_starts_with($m, 'Too many tries'));
});

it('answers in JSON: 401 without a token, and tokens expire after 90 days', function () {
    $this->get('/api/v1/me')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);

    $token = User::factory()->create()->createToken('Pixel', ['app'])->plainTextToken;
    $this->withToken($token)->getJson(route('api.me'))->assertOk();

    $this->travel(91)->days();
    signOutGuards();
    $this->withToken($token)->getJson(route('api.me'))->assertUnauthorized();
});

it('lists signed-in phones on the security page, signs one out, and signs all out on a password reset', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'lagos2026cars']);
    $phone = $user->createToken('Pixel 8', ['app']);
    $user->createToken('iPhone 15', ['app']);

    $this->actingAs($user)->get(route('account.security'))->assertInertia(fn ($page) => $page->has('apps', 2)->where('apps', fn ($apps) => collect($apps)->pluck('name')->sort()->values()->all() === ['Pixel 8', 'iPhone 15']));
    $this->delete(route('account.apps.destroy', $phone->accessToken->id))->assertSessionHas('success');
    expect($user->tokens()->pluck('name')->all())->toBe(['iPhone 15']);

    // Someone else's token can't be revoked from here.
    $other = User::factory()->create()->createToken('Theirs', ['app']);
    $this->delete(route('account.apps.destroy', $other->accessToken->id));
    expect(PersonalAccessToken::whereKey($other->accessToken->id)->exists())->toBeTrue();

    Notification::fake();
    auth()->logout();
    $this->post(route('password.email'), ['email' => 'ada@example.com']);
    $token = null;
    Notification::assertSentTo($user, ResetPasswordLink::class, function (ResetPasswordLink $n) use (&$token) {
        $token = $n->token;

        return true;
    });
    $this->post(route('password.store'), ['token' => $token, 'email' => 'ada@example.com', 'password' => 'abuja2027cars', 'password_confirmation' => 'abuja2027cars']);
    expect($user->tokens()->count())->toBe(0);
});
