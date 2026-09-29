<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Accounts\Notifications\ResetPasswordLink;
use App\Domain\Accounts\Notifications\SignInChanged;
use App\Domain\Audit\AuditLog;
use App\Http\Controllers\Auth\PasswordResetController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

function resetToken(User $user): string
{
    $token = null;
    Notification::assertSentTo($user, ResetPasswordLink::class, function (ResetPasswordLink $n) use (&$token, $user) {
        $token = $n->token;
        expect($n->toMail($user)->actionUrl)->toBe(route('password.reset', ['token' => $n->token, 'email' => $user->email]));

        return true;
    });

    return (string) $token;
}

it('emails a reset link and sets a new password with it', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'lagos2026cars']);

    $this->get(route('password.request', ['email' => 'ada@example.com']))->assertInertia(fn ($page) => $page->component('Auth/ForgotPassword')->where('email', 'ada@example.com'));
    $this->post(route('password.email'), ['email' => ' ADA@example.com '])->assertSessionHas('status', PasswordResetController::SENT);
    $token = resetToken($user);

    $this->get(route('password.reset', ['token' => $token, 'email' => 'ada@example.com']))
        ->assertInertia(fn ($page) => $page->component('Auth/ResetPassword')->where('token', $token)->where('email', 'ada@example.com'));

    $this->post(route('password.store'), ['token' => $token, 'email' => 'ada@example.com', 'password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');
    $this->post(route('password.store'), ['token' => $token, 'email' => 'ada@example.com', 'password' => 'abuja2027cars', 'password_confirmation' => 'abuja2027cars'])
        ->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
    expect(Hash::check('abuja2027cars', $user->fresh()->password))->toBeTrue()
        ->and(AuditLog::where('action', 'account.password_reset')->exists())->toBeTrue();
    Notification::assertSentTo($user, SignInChanged::class);

    // The link works once.
    Auth::logout();
    $this->post(route('password.store'), ['token' => $token, 'email' => 'ada@example.com', 'password' => 'kano2028cars', 'password_confirmation' => 'kano2028cars'])
        ->assertSessionHasErrors('email');
    $this->post(route('login.password'), ['login' => 'ada@example.com', 'password' => 'abuja2027cars'])->assertRedirect();
    $this->assertAuthenticatedAs($user);
});

it('answers the same whether or not the email has an account', function () {
    $this->post(route('password.email'), ['email' => 'nobody@example.com'])->assertSessionHas('status', PasswordResetController::SENT);
    Notification::assertNothingSent();
    $this->post(route('password.email'), ['email' => 'not-an-email'])->assertSessionHasErrors('email');
});

it('lets an email account with no password set one by email', function () {
    $user = User::factory()->create(['email' => 'codes@example.com', 'password' => null]);

    $this->post(route('password.email'), ['email' => 'codes@example.com']);
    $this->post(route('password.store'), ['token' => resetToken($user), 'email' => 'codes@example.com', 'password' => 'lagos2026cars', 'password_confirmation' => 'lagos2026cars'])
        ->assertSessionHasNoErrors();
    expect($user->fresh()->password)->not->toBeNull();
});

it('rejects expired, wrong or other people\'s tokens, and one email a minute', function () {
    $ada = User::factory()->create(['email' => 'ada@example.com', 'password' => 'lagos2026cars']);
    User::factory()->create(['email' => 'obi@example.com', 'password' => 'lagos2026cars']);

    $this->post(route('password.email'), ['email' => 'ada@example.com']);
    $this->post(route('password.email'), ['email' => 'ada@example.com'])->assertSessionHasErrors('email');
    $token = resetToken($ada);

    $try = fn (string $email, string $token) => $this->post(route('password.store'), ['token' => $token, 'email' => $email, 'password' => 'abuja2027cars', 'password_confirmation' => 'abuja2027cars']);
    $try('ada@example.com', 'wrong-token')->assertSessionHasErrors('email');
    $try('obi@example.com', $token)->assertSessionHasErrors('email');

    $this->travel(61)->minutes();
    $try('ada@example.com', $token)->assertSessionHasErrors('email');
    $this->assertGuest();
    expect(Hash::check('lagos2026cars', $ada->fresh()->password))->toBeTrue();
});

it('signs out remembered devices when the password is reset', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'lagos2026cars']);
    $user->forceFill(['remember_token' => 'old-token'])->save();

    $this->post(route('password.email'), ['email' => 'ada@example.com']);
    $this->post(route('password.store'), ['token' => resetToken($user), 'email' => 'ada@example.com', 'password' => 'abuja2027cars', 'password_confirmation' => 'abuja2027cars']);

    expect($user->fresh()->remember_token)->not->toBe('old-token');
});
