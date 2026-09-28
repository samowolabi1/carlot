<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Lots\Models\Lot;
use App\Domain\Sharing\Enums\SharePlatform;
use App\Domain\Sharing\Models\ShareLink;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class);

beforeEach(function () {
    $this->lot = Lot::factory()->active()->create(['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->camry = $this->car($this->lot, 'Toyota', 'Camry', ['year' => 2018, 'price' => 1_250_000_000]);
    $this->share = fn (array $data) => $this->postJson(route('shares.store'), $data);
});

it('makes a short tracked link for a car', function () {
    $response = ($this->share)(['vehicle' => $this->camry->ulid, 'platform' => 'whatsapp'])->assertOk();

    $link = ShareLink::sole();
    expect($response->json('code'))->toBe($link->code)
        ->and($link->code)->toMatch('/^[a-hjkmnp-z2-9]{8}$/')
        ->and($response->json('url'))->toBe(url('/c/'.$link->code))
        ->and($link->platform)->toBe(SharePlatform::WhatsApp)
        ->and($link->vehicle_id)->toBe($this->camry->id)
        ->and($link->lot_id)->toBe($this->lot->id);
});

it('reuses one link per person, car and platform', function () {
    ($this->share)(['vehicle' => $this->camry->ulid, 'platform' => 'whatsapp']);
    ($this->share)(['vehicle' => $this->camry->ulid, 'platform' => 'whatsapp']);
    ($this->share)(['vehicle' => $this->camry->ulid, 'platform' => 'facebook']);

    $buyer = User::factory()->create();
    $this->actingAs($buyer);
    ($this->share)(['vehicle' => $this->camry->ulid, 'platform' => 'whatsapp']);
    ($this->share)(['vehicle' => $this->camry->ulid, 'platform' => 'whatsapp']);

    expect(ShareLink::count())->toBe(3)
        ->and(ShareLink::where('user_id', $buyer->id)->count())->toBe(1);
});

it('shares a lot page too', function () {
    ($this->share)(['lot' => $this->lot->slug, 'platform' => 'native'])->assertOk()->assertJsonPath('cards', null);

    expect(ShareLink::sole()->vehicle_id)->toBeNull();
});

it('will not share cars or lots buyers cannot see', function () {
    $draft = $this->car($this->lot, 'Honda', 'Accord');
    $draft->forceFill(['status' => VehicleStatus::Draft])->save();
    $pending = Lot::factory()->create();

    ($this->share)(['vehicle' => $draft->ulid, 'platform' => 'whatsapp'])->assertNotFound();
    ($this->share)(['lot' => $pending->slug, 'platform' => 'whatsapp'])->assertNotFound();
    ($this->share)(['vehicle' => $this->camry->ulid, 'platform' => 'qr'])->assertUnprocessable();
    ($this->share)(['vehicle' => $this->camry->ulid, 'platform' => 'myspace'])->assertUnprocessable();

    expect(ShareLink::count())->toBe(0);
});

it('redirects to the car with the ref and counts real clicks only', function () {
    $code = ($this->share)(['vehicle' => $this->camry->ulid, 'platform' => 'whatsapp'])->json('code');

    $this->get("/c/{$code}", ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/128.0 Mobile'])
        ->assertRedirect($this->camry->publicPath()."?ref={$code}");
    // WhatsApp and Facebook fetch the link to build the preview; those aren't clicks.
    $this->get("/c/{$code}", ['User-Agent' => 'WhatsApp/2.24.1 A'])->assertRedirect();
    $this->get("/c/{$code}", ['User-Agent' => 'facebookexternalhit/1.1'])->assertRedirect();

    $link = ShareLink::sole();
    expect($link->clicks)->toBe(1)
        ->and($link->last_clicked_at)->not->toBeNull();
});

it('sends people to the lot or search when the car is gone', function () {
    $code = ($this->share)(['vehicle' => $this->camry->ulid, 'platform' => 'copy'])->json('code');

    $this->camry->delete();
    $this->get("/c/{$code}")->assertRedirect(route('lots.show', $this->lot, false)."?ref={$code}");

    $this->lot->update(['status' => 'suspended']);
    $this->get("/c/{$code}")->assertRedirect(route('cars.index'));

    $this->get('/c/zzzzzzzz')->assertNotFound();
});

it('prunes links nobody opened after 90 days, but keeps QR codes', function () {
    ShareLink::create(['vehicle_id' => $this->camry->id, 'lot_id' => $this->lot->id, 'platform' => SharePlatform::WhatsApp]);
    ShareLink::create(['vehicle_id' => $this->camry->id, 'lot_id' => $this->lot->id, 'platform' => SharePlatform::Qr]);
    $used = ShareLink::create(['vehicle_id' => $this->camry->id, 'lot_id' => $this->lot->id, 'platform' => SharePlatform::Facebook]);
    $used->registerClick('Mozilla/5.0 Safari');

    $this->travel(91)->days();
    ShareLink::create(['vehicle_id' => $this->camry->id, 'lot_id' => $this->lot->id, 'platform' => SharePlatform::X]);
    $this->artisan('share-links:prune')->assertSuccessful();

    expect(ShareLink::pluck('platform')->map->value->sort()->values()->all())->toBe(['facebook', 'qr', 'x']);
});
