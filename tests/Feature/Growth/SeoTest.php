<?php

use App\Domain\Lots\Models\Lot;
use App\Domain\Seo\Sitemap;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class);

beforeEach(function () {
    $this->travelTo('2026-10-05 12:00');
    Storage::fake('local');
    $this->ikeja = Lot::factory()->active()->create(['name' => 'Prime Motors', 'city' => 'Ikeja', 'state' => 'Lagos']);
    $this->lekki = Lot::factory()->active()->create(['name' => 'Ace Autos', 'city' => 'Lekki', 'state' => 'Lagos']);
    $this->camry = $this->car($this->ikeja, 'Toyota', 'Camry', ['year' => 2018, 'price' => 1_250_000_000]);
    $this->car($this->lekki, 'Toyota', 'Camry', ['year' => 2017, 'price' => 1_050_000_000]);
    $this->car($this->lekki, 'Honda', 'Accord', ['year' => 2016, 'price' => 790_000_000]);
});

it('renders landing pages for a make, a model, a city and combinations', function () {
    $this->get('/cars/toyota/camry/ikeja')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Marketplace/Search')
        ->where('landing.heading', 'Used Toyota Camry cars for sale in Ikeja')
        ->where('landing.intro', fn ($intro) => str_starts_with($intro, '1 Toyota Camry car for sale in Ikeja at 1 seller on CarYard at ₦12,500,000'))
        ->where('results.total', 1));

    $this->get('/cars/toyota')->assertInertia(fn (Assert $page) => $page
        ->where('results.total', 2)
        ->where('landing.related', fn ($related) => collect($related)->pluck('label')->contains('Toyota Camry') && collect($related)->pluck('label')->contains('Toyota in Lekki')));
    $this->get('/cars/lekki')->assertInertia(fn (Assert $page) => $page->where('landing.heading', 'Used cars for sale in Lekki')->where('results.total', 2));
    $this->get('/cars/honda/lekki')->assertInertia(fn (Assert $page) => $page->where('results.total', 1));

    $html = $this->get('/cars/toyota/camry')->getContent();
    expect($html)->toContain('<title inertia>Used Toyota Camry cars for sale | CarYard</title>')
        ->toContain('<link rel="canonical" href="'.url('/cars/toyota/camry').'">')
        ->not->toContain('noindex');
});

it('returns 404 for unknown segments and noindexes pages with no stock', function () {
    $this->get('/cars/narnia')->assertNotFound();
    $this->get('/cars/toyota/narnia/ikeja')->assertNotFound();

    $this->get('/cars/honda/ikeja')->assertOk()->assertSee('noindex, follow', false)
        ->assertInertia(fn (Assert $page) => $page->where('results.total', 0)->where('landing.intro', fn ($t) => str_contains($t, 'save this search')));
});

it('adds Car and AutoDealer structured data', function () {
    $html = $this->get($this->camry->publicPath())->getContent();
    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    $car = json_decode($m[1], true);
    expect($car)->toMatchArray(['@type' => 'Car', 'name' => $this->camry->title(), 'vehicleModelDate' => '2018'])
        ->and($car['offers'])->toMatchArray(['price' => 12_500_000, 'priceCurrency' => 'NGN', 'availability' => 'https://schema.org/InStock'])
        ->and($car['offers']['seller']['name'])->toBe('Prime Motors')
        ->and($html)->not->toContain($this->camry->vin ?? 'NO-VIN-HERE');

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $this->get(route('lots.show', $this->ikeja))->getContent(), $m);
    expect(json_decode($m[1], true))->toMatchArray(['@type' => 'AutoDealer', 'name' => 'Prime Motors'])
        ->and(json_decode($m[1], true)['address']['addressLocality'])->toBe('Ikeja');
});

it('escapes structured data so a description cannot break out of the script tag', function () {
    $this->camry->forceFill(['description' => 'Clean car </script><script>alert(1)</script>'])->save();
    expect($this->get($this->camry->publicPath())->getContent())->not->toContain('</script><script>alert(1)');
});

it('builds a sitemap of live pages, and robots.txt points to it in production', function () {
    $this->artisan('sitemap:generate')->assertSuccessful();
    $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();

    expect($xml)->toContain('<loc>'.url($this->camry->publicPath()).'</loc>')
        ->toContain('<loc>'.url('/cars/toyota/camry/ikeja').'</loc>')
        ->toContain('<loc>'.url('/cars/lekki').'</loc>')
        ->toContain('<loc>'.route('lots.show', $this->ikeja).'</loc>')
        ->not->toContain('/cars/honda/ikeja');

    $this->camry->forceFill(['status' => 'hidden'])->save();
    Sitemap::generate();
    expect($this->get('/sitemap.xml')->getContent())->not->toContain($this->camry->publicPath());

    $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /');
    app()->detectEnvironment(fn () => 'production');
    $this->get('/robots.txt')->assertSee('Sitemap: '.url('/sitemap.xml'))->assertSee('Disallow: /dealer');
});
