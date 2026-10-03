<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Actions\PublishVehicle;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Support\VehicleStateMachine;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Social\Gateways\SocialPublisher;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Social\Models\SocialPost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Tests\Support\MarketplaceFixtures;

uses(MarketplaceFixtures::class);

beforeEach(function () {
    $this->media = Storage::fake(config('lotlink.media_disk'));
    $this->owner = User::factory()->staff()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active', 'city' => 'Ikeja']);
    $this->existing = $this->car($this->lot, 'Toyota', 'Camry');

    // A publisher that records posts, and can be told to fail.
    $this->publisher = new class implements SocialPublisher
    {
        public array $posts = [];

        public bool $fail = false;

        public function authorizeUrl(string $state, string $redirectUri): string
        {
            return $redirectUri.'?code=abc&state='.$state;
        }

        public function accounts(string $code, string $redirectUri): array
        {
            return [
                ['provider' => 'facebook', 'page_id' => '111', 'name' => 'Prime Motors Page', 'token' => 'page-token-secret', 'expires_at' => null],
                ['provider' => 'instagram', 'page_id' => '222', 'name' => '@primemotors', 'token' => 'page-token-secret', 'expires_at' => null],
            ];
        }

        public function publish(SocialAccount $account, string $imageUrl, string $caption): string
        {
            if ($this->fail) {
                throw new RuntimeException('Token expired');
            }
            $this->posts[] = compact('account', 'imageUrl', 'caption');

            return 'post_'.count($this->posts);
        }
    };
    $this->app->instance(SocialPublisher::class, $this->publisher);

    $this->draft = function (): Vehicle {
        $v = Vehicle::factory()->withPhoto()->create([
            'lot_id' => $this->lot->id, 'make_id' => $this->existing->make_id, 'vehicle_model_id' => $this->existing->vehicle_model_id,
            'year' => 2019, 'price' => 1_350_000_000, 'mileage_km' => 50000, 'condition' => 'foreign_used', 'transmission' => 'automatic', 'fuel' => 'petrol',
        ])->refresh();
        $this->media->put($v->cover->path, (string) (new ImageManager(new Driver))->create(40, 30)->fill('#888888')->toWebp());

        return $v;
    };
});

it('connects a Facebook Page and its Instagram account through the OAuth callback', function () {
    $this->actingAs($this->owner)->get(route('social.callback', ['code' => 'x', 'state' => 'forged']))->assertForbidden();
    $this->actingAs($this->owner)->get(route('dealer.social.connect', $this->lot));
    $state = session('social_oauth.state');
    $this->actingAs($this->owner)->get(route('social.callback', ['code' => 'abc', 'state' => $state]))
        ->assertRedirect(route('dealer.settings', $this->lot).'#social')->assertSessionHas('success');

    expect(SocialAccount::withoutGlobalScopes()->count())->toBe(2)
        ->and(DB::table('social_accounts')->value('token'))->not->toBe('page-token-secret') // encrypted at rest
        ->and(SocialAccount::withoutGlobalScopes()->where('provider', 'facebook')->sole()->token)->toBe('page-token-secret');

    $this->actingAs($this->owner)->get(route('dealer.settings', $this->lot))
        ->assertInertia(fn (Assert $page) => $page->has('social.accounts', 2)->missing('social.accounts.0.token'));
});

it('posts new listings once, with a JPEG and a tracked link, and records failures', function () {
    $this->actingAs($this->owner)->get(route('dealer.social.connect', $this->lot));
    $this->actingAs($this->owner)->get(route('social.callback', ['code' => 'abc', 'state' => session('social_oauth.state')]));

    $car = ($this->draft)();
    app(PublishVehicle::class)->run($car);

    expect($this->publisher->posts)->toHaveCount(2)
        ->and($this->publisher->posts[0]['imageUrl'])->toEndWith('.jpg')
        ->and($this->publisher->posts[0]['caption'])->toContain('2019 Toyota Camry — ₦13,500,000')->toContain('/c/')
        ->and(SocialPost::withoutGlobalScopes()->where('status', 'posted')->count())->toBe(2);

    // Publishing again (hide, then list) doesn't post twice.
    $car->refresh();
    app(VehicleStateMachine::class)->transition($car, VehicleStatus::Hidden);
    app(PublishVehicle::class)->run($car->refresh());
    expect($this->publisher->posts)->toHaveCount(2);

    $this->publisher->fail = true;
    app(PublishVehicle::class)->run(($this->draft)());
    $failed = SocialPost::withoutGlobalScopes()->with('vehicle')->where('status', 'failed')->get();
    expect($failed)->toHaveCount(2)->and($failed[0]->error)->toBe('Token expired')
        ->and(SocialAccount::withoutGlobalScopes()->first()->last_error)->toBe('Token expired');

    $this->publisher->fail = false;
    $account = SocialAccount::withoutGlobalScopes()->where('provider', 'facebook')->sole();
    $this->actingAs($this->owner)->post(route('dealer.social.retry', [$this->lot, $account, $failed[0]->vehicle]))->assertSessionHas('success');
    expect(SocialPost::withoutGlobalScopes()->where('social_account_id', $account->id)->where('vehicle_id', $failed[0]->vehicle_id)->value('status'))->toBe('posted');
});

it('keeps social settings to owners and managers of the seller', function () {
    $other = User::factory()->staff()->create();
    app(CreateLot::class)->run($other, ['name' => 'Other Autos', 'phone' => '+2348021119999']);
    $this->actingAs($other)->get(route('dealer.social.connect', $this->lot))->assertForbidden();

    $account = SocialAccount::withoutGlobalScopes()->create(['lot_id' => $this->lot->id, 'provider' => 'facebook', 'page_id' => '1', 'name' => 'Page', 'token' => 't']);
    $this->actingAs($other)->patch(route('dealer.social.update', [$this->lot, $account]), ['auto_post' => false])->assertForbidden();
    $otherLot = $other->lots()->first();
    $this->actingAs($other)->delete(route('dealer.social.destroy', [$otherLot, $account]))->assertNotFound();

    $this->actingAs($this->owner)->patch(route('dealer.social.update', [$this->lot, $account]), ['auto_post' => false])->assertSessionHas('success');
    expect($account->fresh()->auto_post)->toBeFalse();
});
