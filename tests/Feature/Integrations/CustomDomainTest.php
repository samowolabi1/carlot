<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Domains\DnsLookup;
use App\Domain\Lots\Models\Plan;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.url' => 'https://lotlink.app']);
    $this->dns = new class implements DnsLookup
    {
        public array $records = [];

        public function txt(string $host): array
        {
            return $this->records[$host] ?? [];
        }
    };
    $this->app->instance(DnsLookup::class, $this->dns);

    $this->owner = User::factory()->staff()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->forceFill(['status' => 'active', 'plan_id' => Plan::where('code', 'enterprise')->value('id')])->save();
});

it('lets an Enterprise lot add its domain, prove it with DNS and serve the mini-site there', function () {
    $this->actingAs($this->owner)->put(route('dealer.domain.store', $this->lot), ['domain' => 'https://Cars.PrimeMotors.ng/'])->assertSessionHas('success');
    $lot = $this->lot->fresh();
    expect($lot->custom_domain)->toBe('cars.primemotors.ng')->and($lot->domain_verified_at)->toBeNull();

    // Not live until verified.
    $this->get('http://cars.primemotors.ng/')->assertInertia(fn (Assert $page) => $page->component('Home'));
    $this->get('/internal/domains/allowed?domain=cars.primemotors.ng')->assertNotFound();

    $this->actingAs($this->owner)->post(route('dealer.domain.verify', $this->lot))->assertSessionHas('error');
    $this->dns->records['_lotlink.cars.primemotors.ng'] = ['"lotlink-verify='.$lot->domain_token.'"'];
    $this->actingAs($this->owner)->post(route('dealer.domain.verify', $this->lot))->assertSessionHas('success');

    expect($this->lot->fresh()->domain_verified_at)->not->toBeNull();
    auth()->logout();
    $this->get('http://cars.primemotors.ng/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Marketplace/Lot')->where('lot.name', 'Prime Motors'))
        ->assertHeaderMissing('X-Frame-Options');
    $this->get('/internal/domains/allowed?domain=cars.primemotors.ng')->assertOk();
    $this->get('/internal/domains/allowed?domain=evil.example')->assertNotFound();
    $this->get('/internal/domains/allowed?domain=lotlink.app')->assertOk();
});

it('refuses bad or taken domains, other plans and other staff', function () {
    $this->actingAs($this->owner)->put(route('dealer.domain.store', $this->lot), ['domain' => 'not a domain'])->assertSessionHasErrors('domain');
    $this->actingAs($this->owner)->put(route('dealer.domain.store', $this->lot), ['domain' => 'shop.lotlink.app'])->assertSessionHasErrors('domain');

    $other = User::factory()->staff()->create();
    $otherLot = app(CreateLot::class)->run($other, ['name' => 'Other Autos', 'phone' => '+2348021119999']);
    $otherLot->forceFill(['plan_id' => Plan::where('code', 'enterprise')->value('id'), 'custom_domain' => 'taken.ng'])->save();
    $this->actingAs($this->owner)->put(route('dealer.domain.store', $this->lot), ['domain' => 'taken.ng'])->assertSessionHasErrors('domain');
    $this->actingAs($other)->put(route('dealer.domain.store', $this->lot), ['domain' => 'mine.ng'])->assertForbidden();

    $this->lot->forceFill(['plan_id' => Plan::where('code', 'pro')->value('id')])->save();
    $this->actingAs($this->owner)->put(route('dealer.domain.store', $this->lot), ['domain' => 'primemotors.ng'])->assertForbidden();
    $this->actingAs($this->owner)->get(route('dealer.minisite', $this->lot))->assertInertia(fn (Assert $page) => $page->where('domain.allowed', false));
});
