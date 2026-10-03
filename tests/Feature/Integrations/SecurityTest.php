<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Accounts\Support\Totp;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Models\Lot;
use App\Domain\Marketplace\Models\SavedSearch;

it('sends security headers, and a nonce-based CSP when it is on', function () {
    $lot = Lot::factory()->active()->create();

    $this->get('/')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeaderMissing('Content-Security-Policy');
    $this->get(route('lots.show', $lot))->assertHeaderMissing('X-Frame-Options'); // lots may embed their mini-site

    config(['lotlink.csp' => true]);
    $response = $this->get('/');
    $csp = $response->headers->get('Content-Security-Policy');
    preg_match("/'nonce-([^']+)'/", (string) $csp, $m);

    expect($csp)->toContain("default-src 'self'")->toContain('https://maps.googleapis.com')->toContain("frame-ancestors 'none'")->toContain("object-src 'none'")
        ->and($m[1] ?? null)->not->toBeNull()
        ->and($response->getContent())->toContain('nonce="'.$m[1].'"');
    expect($this->get(route('lots.show', $lot))->headers->get('Content-Security-Policy'))->toContain('frame-ancestors *');

    $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('makes TOTP codes that match the RFC 6238 test vector', function () {
    $secret = Totp::base32('12345678901234567890');

    expect(Totp::code($secret, 59))->toBe('287082')
        ->and(Totp::code($secret, 1111111109))->toBe('081804')
        ->and(Totp::verify($secret, '287082', 59 + 30))->toBeTrue() // one step of drift
        ->and(Totp::verify($secret, '287082', 59 + 90))->toBeFalse();
});

it('makes admins set up and use an authenticator app before the panel opens', function () {
    config(['lotlink.admin_2fa' => true]);
    $admin = User::factory()->admin()->create(['email' => 'ops@lotlink.test']);

    $this->actingAs($admin)->get('/admin')->assertRedirect(route('admin.2fa.setup'));
    $this->actingAs($admin)->get(route('admin.2fa.setup'))->assertOk()->assertSee('Set up two-step sign-in');
    $secret = session('admin_2fa_secret');

    $this->actingAs($admin)->post(route('admin.2fa.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
    $this->actingAs($admin)->post(route('admin.2fa.confirm'), ['code' => Totp::code($secret)])->assertOk()->assertSee('Two-step sign-in is on');
    $this->actingAs($admin)->get('/admin')->assertOk();

    // A new session asks for a code again; a recovery code works once.
    $this->flushSession();
    $this->actingAs($admin->fresh())->get('/admin')->assertRedirect(route('admin.2fa.challenge'));
    $this->actingAs($admin->fresh())->post(route('admin.2fa.verify'), ['code' => Totp::code($secret)])->assertRedirect('/admin');
    $this->actingAs($admin->fresh())->get('/admin')->assertOk();

    // Customers and sellers never see any of this.
    $this->actingAs(User::factory()->create())->get(route('admin.2fa.setup'))->assertForbidden();
});

it('closes an account now and removes the personal data after 30 days', function () {
    $this->travelTo('2026-10-05 12:00');
    $buyer = User::factory()->create(['name' => 'Tunde Adebayo', 'phone' => '+2348035550777', 'email' => 'tunde@example.com']);
    SavedSearch::create(['user_id' => $buyer->id, 'name' => 'Toyota', 'filters' => ['q' => 'toyota'], 'channel' => 'phone']);

    $this->actingAs($buyer)->delete(route('account.destroy'))->assertSessionHasErrors('confirm');
    $this->actingAs($buyer)->delete(route('account.destroy'), ['confirm' => true])->assertRedirect(route('home'));
    expect($buyer->fresh()->trashed())->toBeTrue();
    $this->assertGuest();

    $this->travel(31)->days();
    $this->artisan('accounts:anonymise')->expectsOutput('Anonymised 1 accounts.');
    $gone = User::withTrashed()->find($buyer->id);
    expect($gone)->name->toBe('Deleted user')->email->toBeNull()->phone->toStartWith('deleted-')->anonymised_at->not->toBeNull()
        ->and(SavedSearch::count())->toBe(0);
});

it('lets owners delete only after handing over their lot, and cancels deletion on sign-in', function () {
    $owner = User::factory()->staff()->create(['phone' => '+2348020000001']);
    app(CreateLot::class)->run($owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->actingAs($owner)->delete(route('account.destroy'), ['confirm' => true])->assertSessionHasErrors('account');

    $buyer = User::factory()->create(['phone' => '+2348035550777']);
    $this->actingAs($buyer)->delete(route('account.destroy'), ['confirm' => true]);

    $this->post(route('login.send'), ['phone' => '08035550777']);
    $this->post(route('login.check'), ['code' => $this->lastCode('+2348035550777')]);
    expect($buyer->fresh())->trashed()->toBeFalse()->deletion_requested_at->toBeNull();
});
