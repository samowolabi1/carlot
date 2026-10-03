<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Sharing\Models\ShareLink;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class);

beforeEach(function () {
    $this->owner = User::factory()->staff()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active', 'whatsapp' => '+2348021112233', 'tagline' => 'Clean cars, papers ready']);
    $this->car($this->lot, 'Toyota', 'Camry');
    $this->car($this->lot, 'Honda', 'Accord');
});

it('shows the mini-site and QR page to the seller\'s team', function () {
    $this->actingAs($this->owner)->get(route('dealer.minisite', $this->lot))->assertInertia(fn (Assert $page) => $page
        ->component('Dealer/MiniSite')->where('site.url', route('lots.show', $this->lot))->where('stickers', 2));

    $other = User::factory()->staff()->create();
    app(CreateLot::class)->run($other, ['name' => 'Other Autos', 'phone' => '+2348021119999']);
    $this->actingAs($other)->get(route('dealer.minisite', $this->lot))->assertForbidden();
    $this->actingAs($other)->get(route('dealer.qr.poster', $this->lot))->assertForbidden();
    $this->actingAs($other)->get(route('dealer.qr.stickers', $this->lot))->assertForbidden();
});

it('makes an A4 or A3 poster whose QR is a tracked link to the mini-site', function () {
    foreach (['a4', 'a3'] as $size) {
        $response = $this->actingAs($this->owner)->get(route('dealer.qr.poster', ['lot' => $this->lot, 'size' => $size]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
    }

    $link = ShareLink::where('platform', 'qr')->whereNull('vehicle_id')->sole();
    auth()->logout();
    $this->get($link->url())->assertRedirect();
    expect($link->fresh()->clicks)->toBe(1);
});

it('prints a windscreen sticker per car, each with its own tracked QR', function () {
    $this->actingAs($this->owner)->get(route('dealer.qr.stickers', $this->lot))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect(ShareLink::where('platform', 'qr')->whereNotNull('vehicle_id')->count())->toBe(2);
});
