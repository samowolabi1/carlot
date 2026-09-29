<?php

use App\Domain\Accounts\Actions\SignInWithGoogle;
use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Accounts\Notifications\SignInChanged;
use App\Domain\Audit\AuditLog;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;

beforeEach(function () {
    Notification::fake();
    config(['services.google.client_id' => 'client-id', 'services.google.client_secret' => 'secret']);

    // Google says: this person, with this (verified or not) email.
    $this->google = function (string $id = 'g-123', ?string $email = 'ada.obi@gmail.com', bool $verified = true, string $name = 'Ada Obi') {
        $user = (new GoogleUser)->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $verified])
            ->map(['id' => $id, 'email' => $email, 'name' => $name]);
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($user);
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth?client_id=client-id'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    };
});

it('shows Google only when it is set up', function () {
    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('google', true));
    ($this->google)();
    $this->get(route('login.google'))->assertRedirect('https://accounts.google.com/o/oauth2/auth?client_id=client-id');

    config(['services.google.client_id' => null]);
    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('google', false));
    $this->get(route('login.google'))->assertNotFound();
});

it('creates an account with Google, then signs back in to it', function () {
    ($this->google)();

    $this->get(route('login.google.callback', ['code' => 'x', 'state' => 'y']))->assertRedirect(route('home'));

    $user = User::sole();
    expect($user)->email->toBe('ada.obi@gmail.com')->name->toBe('Ada Obi')->google_id->toBe('g-123')->phone->toBeNull()
        ->role->toBe(UserRole::Customer)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'account.created_with_google')->exists())->toBeTrue();
    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'));
    $this->get(route('login.google.callback'))->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
});

it('signs in to the account with the same verified email and links Google to it', function () {
    $existing = User::factory()->create(['email' => 'ada.obi@gmail.com', 'phone' => '+2348031112222', 'name' => 'Ada']);
    ($this->google)();

    $this->get(route('login.google.callback'))->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($existing);
    expect($existing->fresh())->google_id->toBe('g-123')->name->toBe('Ada');
    Notification::assertSentTo($existing, SignInChanged::class);
});

it('never links by an email Google hasn\'t verified', function () {
    $existing = User::factory()->create(['email' => 'ada.obi@gmail.com']);
    ($this->google)('g-999', 'ada.obi@gmail.com', verified: false);

    $this->get(route('login.google.callback'))->assertRedirect(route('home'));

    $new = User::where('google_id', 'g-999')->sole();
    expect($new->is($existing))->toBeFalse()->and($new->email)->toBeNull()->and($existing->fresh()->google_id)->toBeNull();
});

it('connects Google to a WhatsApp account from the security page, and disconnects it', function () {
    $user = User::factory()->create(['phone' => '+2348031112222', 'email' => null]);
    ($this->google)();

    $this->actingAs($user)->get(route('login.google'));
    $this->get(route('login.google.callback'))->assertRedirect(route('account.security'))->assertSessionHas('success');

    expect($user->fresh())->google_id->toBe('g-123')->email->toBe('ada.obi@gmail.com')
        ->and(AuditLog::where('action', 'account.google_connected')->exists())->toBeTrue();
    $this->get(route('account.security'))->assertInertia(fn ($page) => $page->where('google.connected', true));

    $this->delete(route('account.google.destroy'))->assertSessionHas('success');
    expect($user->fresh()->google_id)->toBeNull();
});

it('won\'t connect a Google account that belongs to someone else', function () {
    User::factory()->create(['email' => 'other@example.com'])->forceFill(['google_id' => 'g-123'])->save();
    $user = User::factory()->create(['email' => 'ada@example.com']);
    ($this->google)();

    $this->actingAs($user)->get(route('login.google'));
    $this->get(route('login.google.callback'))->assertRedirect(route('account.security'))->assertSessionHas('error', SignInWithGoogle::TAKEN);
    expect($user->fresh()->google_id)->toBeNull();
    $this->assertAuthenticatedAs($user);
});

it('goes back to sign-in when Google fails or is cancelled', function () {
    $this->get(route('login.google.callback', ['error' => 'access_denied']))->assertRedirect(route('login'))->assertSessionHas('error', 'Google sign-in was cancelled.');

    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('user')->andThrow(new InvalidStateException);
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    $this->get(route('login.google.callback', ['code' => 'x']))->assertRedirect(route('login'))->assertSessionHas('error');
    $this->assertGuest();
});

it('keeps closed accounts closed', function () {
    $gone = User::factory()->create(['email' => 'ada.obi@gmail.com']);
    $gone->forceFill(['anonymised_at' => now(), 'deletion_requested_at' => now()->subDays(40)])->save();
    $gone->delete();
    ($this->google)();

    $this->get(route('login.google.callback'))->assertRedirect(route('login'))->assertSessionHas('error');
    $this->assertGuest();
});
