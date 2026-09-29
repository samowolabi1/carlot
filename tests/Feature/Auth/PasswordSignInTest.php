<?php

use App\Domain\Accounts\Actions\LogInWithPassword;
use App\Domain\Accounts\Models\User;
use App\Domain\Accounts\Notifications\SignInChanged;
use App\Domain\Audit\AuditLog;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

it('lets a signed-in person add a password, then sign in with email or WhatsApp number', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'phone' => '+2348031112222', 'password' => null]);

    $this->actingAs($user)->get(route('account.security'))
        ->assertInertia(fn ($page) => $page->component('Account/Security')->where('hasPassword', false)->where('email', 'ada@example.com'));

    // Weak or mistyped passwords are refused.
    $this->put(route('account.password'), ['password' => 'short1', 'password_confirmation' => 'short1'])->assertSessionHasErrors('password');
    $this->put(route('account.password'), ['password' => 'onlyletters', 'password_confirmation' => 'onlyletters'])->assertSessionHasErrors('password');
    $this->put(route('account.password'), ['password' => 'lagos2026cars', 'password_confirmation' => 'lagos2026car'])->assertSessionHasErrors('password');

    // The first password needs only the signed-in session.
    $this->put(route('account.password'), ['password' => 'lagos2026cars', 'password_confirmation' => 'lagos2026cars'])
        ->assertSessionHasNoErrors()->assertSessionHas('success');
    expect($user->fresh()->password_changed_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'account.password_added')->exists())->toBeTrue();
    Notification::assertSentTo($user, SignInChanged::class);

    $this->post(route('logout'));
    $this->post(route('login.password'), ['login' => ' ADA@example.com', 'password' => 'lagos2026cars'])->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);

    $this->post(route('logout'));
    $this->post(route('login.password'), ['login' => '0803 111 2222', 'password' => 'lagos2026cars'])->assertRedirect(route('home'));
    $this->assertAuthenticatedAs($user);
});

it('gives one message for a wrong password, an unknown account and an account with no password', function () {
    User::factory()->create(['email' => 'ada@example.com', 'password' => 'lagos2026cars']);
    User::factory()->create(['email' => 'codes@example.com', 'password' => null]);

    foreach ([['ada@example.com', 'wrong2026'], ['nobody@example.com', 'lagos2026cars'], ['codes@example.com', 'anything1'], ['0801', 'lagos2026cars']] as [$login, $password]) {
        $this->post(route('login.password'), ['login' => $login, 'password' => $password])
            ->assertSessionHasErrors(['login' => LogInWithPassword::FAILED]);
    }
    $this->assertGuest();
    expect(User::count())->toBe(2); // never creates accounts
});

it('locks an account out for a minute after five wrong passwords', function () {
    User::factory()->create(['email' => 'ada@example.com', 'password' => 'lagos2026cars']);

    foreach (range(1, 5) as $_) {
        $this->post(route('login.password'), ['login' => 'ada@example.com', 'password' => 'guess1234']);
    }
    $this->post(route('login.password'), ['login' => 'ada@example.com', 'password' => 'lagos2026cars'])
        ->assertSessionHasErrors('login');
    $this->assertGuest();

    $this->travel(61)->seconds();
    $this->post(route('login.password'), ['login' => 'ada@example.com', 'password' => 'lagos2026cars'])->assertRedirect();
    $this->assertAuthenticated();
});

it('changes and removes a password only with the current one', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'lagos2026cars']);
    $this->actingAs($user);

    $this->put(route('account.password'), ['password' => 'abuja2027cars', 'password_confirmation' => 'abuja2027cars'])->assertSessionHasErrors('current_password');
    $this->put(route('account.password'), ['current_password' => 'nope1234', 'password' => 'abuja2027cars', 'password_confirmation' => 'abuja2027cars'])->assertSessionHasErrors('current_password');
    $this->put(route('account.password'), ['current_password' => 'lagos2026cars', 'password' => 'abuja2027cars', 'password_confirmation' => 'abuja2027cars'])->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);

    $this->delete(route('account.password.destroy'), ['current_password' => 'lagos2026cars'])->assertSessionHasErrors('current_password');
    $this->delete(route('account.password.destroy'), ['current_password' => 'abuja2027cars'])->assertSessionHasNoErrors();
    expect($user->fresh()->password)->toBeNull()
        ->and(AuditLog::where('user_id', $user->id)->pluck('action')->all())->toBe(['account.password_changed', 'account.password_removed']);

    // Codes still work; the password no longer does.
    $this->post(route('logout'));
    $this->post(route('login.password'), ['login' => 'ada@example.com', 'password' => 'abuja2027cars'])->assertSessionHasErrors('login');
});

it('keeps admins\' passwords, since the admin panel needs one', function () {
    $admin = User::factory()->admin()->create(['password' => 'lagos2026cars']);

    $this->actingAs($admin)->delete(route('account.password.destroy'), ['current_password' => 'lagos2026cars'])->assertSessionHasErrors('current_password');
    expect($admin->fresh()->password)->not->toBeNull();
});

it('reopens an account closed in the last 30 days, but not one that is gone', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'lagos2026cars']);
    $user->forceFill(['deletion_requested_at' => now()])->save();
    $user->delete();

    $this->post(route('login.password'), ['login' => 'ada@example.com', 'password' => 'lagos2026cars'])->assertRedirect();
    expect($user->fresh())->trashed()->toBeFalse()->deletion_requested_at->toBeNull();

    $this->post(route('logout'));
    $user->forceFill(['anonymised_at' => now()])->save();
    $user->delete();
    $this->post(route('login.password'), ['login' => 'ada@example.com', 'password' => 'lagos2026cars'])->assertSessionHasErrors('login');
    $this->assertGuest();
});

it('offers the password form on the sign-in page and keeps the security page to signed-in people', function () {
    $this->get(route('login', ['method' => 'password']))->assertInertia(fn ($page) => $page->component('Auth/Login')->where('method', 'password')->where('google', false));
    $this->get(route('account.security'))->assertRedirect(route('login'));
});
