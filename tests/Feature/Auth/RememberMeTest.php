<?php

use App\Domain\Accounts\Models\User;
use Filament\Pages\Auth\Login as AdminLogin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Cookie;

beforeEach(fn () => $this->freezeTime());

/** The remember cookie on a response, if any. */
function rememberCookie(TestResponse $response): ?Cookie
{
    $name = Auth::guard('web')->getRecallerName();

    return collect($response->headers->getCookies())->first(fn (Cookie $c) => $c->getName() === $name);
}

function expectRememberedForAWeek(TestResponse $response): void
{
    $cookie = rememberCookie($response);
    expect($cookie)->not->toBeNull()
        ->and($cookie->getExpiresTime())->toBe(now()->addWeek()->getTimestamp());
}

it('keeps a code sign-in for a week only when ticked', function () {
    $this->post(route('login.send'), ['phone' => '0803 123 4412', 'remember' => true]);
    expectRememberedForAWeek($this->post(route('login.check'), ['code' => $this->lastCode('+2348031234412')]));

    $this->post(route('logout'));
    $this->travel(2)->minutes();
    $this->post(route('login.send'), ['phone' => '0803 123 4412', 'remember' => false]);
    $response = $this->post(route('login.check'), ['code' => $this->lastCode('+2348031234412')]);
    $this->assertAuthenticated();
    expect(rememberCookie($response))->toBeNull();
});

it('keeps a password sign-in for a week only when ticked', function () {
    User::factory()->create(['email' => 'ada@example.com', 'password' => 'lagos2026cars']);

    expectRememberedForAWeek($this->post(route('login.password'), ['login' => 'ada@example.com', 'password' => 'lagos2026cars', 'remember' => true]));

    $this->post(route('logout'));
    $response = $this->post(route('login.password'), ['login' => 'ada@example.com', 'password' => 'lagos2026cars']);
    $this->assertAuthenticated();
    expect(rememberCookie($response))->toBeNull();
});

it('carries the choice through Google', function () {
    config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));
    $provider->shouldReceive('user')->andReturn((new GoogleUser)->setRaw(['email_verified' => true])->map(['id' => 'g-1', 'email' => 'ada@gmail.com', 'name' => 'Ada']));
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $this->get(route('login.google', ['remember' => 1]));
    expectRememberedForAWeek($this->get(route('login.google.callback')));

    $this->post(route('logout'));
    $this->get(route('login.google'));
    expect(rememberCookie($this->get(route('login.google.callback'))))->toBeNull();
});

it('lets a remembered visitor back in after their session ends', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'lagos2026cars']);
    $cookie = rememberCookie($this->post(route('login.password'), ['login' => 'ada@example.com', 'password' => 'lagos2026cars', 'remember' => true]));

    // A new browser session with only the remember cookie (the browser drops it after a week): still signed in.
    $this->flushSession();
    Auth::forgetGuards();
    $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue())->get(route('account'))->assertOk();
    $this->assertAuthenticatedAs($user);
});

it('keeps the device signed in after a password change if it was remembered', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'lagos2026cars']);

    $response = $this->actingAs($user)->withCookie(Auth::guard('web')->getRecallerName(), 'old')
        ->put(route('account.password'), ['current_password' => 'lagos2026cars', 'password' => 'abuja2027cars', 'password_confirmation' => 'abuja2027cars']);
    expectRememberedForAWeek($response);
});

it('doesn\'t start remembering a device on a password change', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'password' => 'lagos2026cars']);

    $response = $this->actingAs($user)
        ->put(route('account.password'), ['current_password' => 'lagos2026cars', 'password' => 'abuja2027cars', 'password_confirmation' => 'abuja2027cars']);
    $this->assertAuthenticatedAs($user);
    expect(rememberCookie($response))->toBeNull();
});

it('labels and honours the admin panel\'s remember box', function () {
    $admin = User::factory()->admin()->create(['email' => 'root@lotlink.test', 'password' => 'password']);

    Livewire::test(AdminLogin::class)
        ->assertSee('Keep me signed in for a week')
        ->fillForm(['email' => 'root@lotlink.test', 'password' => 'password', 'remember' => true])
        ->call('authenticate');

    $this->assertAuthenticatedAs($admin);
    expect(Auth::guard('web')->viaRemember())->toBeFalse()
        ->and($admin->fresh()->remember_token)->not->toBeNull();
    expect(config('auth.guards.web.remember'))->toBe(7 * 24 * 60);
});
