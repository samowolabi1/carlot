<?php

use App\Domain\Inventory\Actions\SaveVehiclePrice;
use App\Domain\Lots\Models\Lot;
use App\Domain\Sharing\Enums\SharePlatform;
use App\Domain\Sharing\Jobs\RenderShareCard;
use App\Domain\Sharing\Models\ShareLink;
use App\Domain\Sharing\Support\ShareCard;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class);

beforeEach(function () {
    config(['lotlink.share_cards' => true]);
    $this->disk = Storage::fake(config('lotlink.media_disk'));
    $this->lot = Lot::factory()->active()->create(['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->camry = $this->car($this->lot, 'Toyota', 'Camry', ['year' => 2018, 'price' => 1_250_000_000]);

    // A real cover photo so the photo layer is drawn too.
    $photo = imagecreatetruecolor(1600, 1000);
    imagefill($photo, 0, 0, (int) imagecolorallocate($photo, 120, 140, 160));
    ob_start();
    imagewebp($photo);
    $this->disk->put($this->camry->cover->path, (string) ob_get_clean());
});

function cardSize(string $bytes): array
{
    $info = getimagesizefromstring($bytes);

    return [$info[0], $info[1], $info['mime']];
}

it('renders feed and Status cards with a QR code that opens the car', function () {
    RenderShareCard::dispatchSync($this->camry->id);

    $this->camry->refresh();
    $cards = app(ShareCard::class);
    expect($this->camry->share_card_hash)->not->toBeNull()
        ->and(cardSize($this->disk->get($cards->path($this->camry, 'square'))))->toBe([1080, 1080, 'image/png'])
        ->and(cardSize($this->disk->get($cards->path($this->camry, 'story'))))->toBe([1080, 1920, 'image/png'])
        ->and(ShareLink::sole()->platform)->toBe(SharePlatform::Qr);
});

it('only re-renders when what the card shows changes', function () {
    RenderShareCard::dispatchSync($this->camry->id);
    $first = $this->camry->refresh()->share_card_hash;

    RenderShareCard::dispatchSync($this->camry->id);
    expect($this->camry->refresh()->share_card_hash)->toBe($first);

    app(SaveVehiclePrice::class)->run($this->camry, $this->lot->owner, 1_150_000_000, true);

    $second = $this->camry->refresh()->share_card_hash;
    expect($second)->not->toBe($first)
        ->and($this->disk->files("share-cards/{$this->camry->ulid}"))->toHaveCount(2)
        ->and(collect($this->disk->files("share-cards/{$this->camry->ulid}"))->every(fn ($f) => str_contains($f, substr($second, 0, 12))))->toBeTrue();
});

it('re-renders when the lot changes its name or phone', function () {
    RenderShareCard::dispatchSync($this->camry->id);
    $first = $this->camry->refresh()->share_card_hash;

    $this->lot->update(['phone' => '+2348039998877']);

    expect($this->camry->refresh()->share_card_hash)->not->toBe($first);
});

it('gives the share API and link previews the card', function () {
    $response = $this->postJson(route('shares.store'), ['vehicle' => $this->camry->ulid, 'platform' => 'whatsapp'])->assertOk();

    $this->camry->refresh();
    expect($response->json('cards.square'))->toBe(app(ShareCard::class)->urls($this->camry)['square']);

    $this->get($this->camry->publicPath())->assertSee('<meta property="og:image" content="'.$response->json('cards.square').'">', false);
});

it('does not render cards for cars buyers cannot see', function () {
    $this->lot->update(['status' => 'suspended']);

    RenderShareCard::dispatchSync($this->camry->id);

    expect($this->camry->refresh()->share_card_hash)->toBeNull()
        ->and($this->disk->allFiles('share-cards'))->toBe([]);
});
