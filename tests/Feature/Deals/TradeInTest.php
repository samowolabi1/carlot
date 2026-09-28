<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Deals\Enums\TradeInStatus;
use App\Domain\Deals\Models\TradeIn;
use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Models\VehicleModel;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Actions\CreateLot;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00', 'UTC'));
    $this->owner = User::factory()->staff()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active']);
    $this->car = Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $this->lot->id]);
    $this->buyer = User::factory()->create(['name' => 'Ibrahim Kabir', 'phone' => '+2348035550001']);
    $this->make = Make::firstOrCreate(['slug' => 'honda'], ['name' => 'Honda']);
    $this->model = VehicleModel::firstOrCreate(['make_id' => $this->make->id, 'slug' => 'civic'], ['name' => 'Civic', 'approved_at' => now()]);

    $this->submit = fn (array $data = [], ?User $as = null) => $this->actingAs($as ?? $this->buyer)->post(route('trade-ins.store', $this->lot->slug), [
        'make_id' => $this->make->id,
        'vehicle_model_id' => $this->model->id,
        'year' => 2014,
        'mileage_km' => '118,000',
        'condition' => 'good',
        'notes' => 'AC recently serviced',
        'photos' => [UploadedFile::fake()->image('front.jpg', 1200, 900), UploadedFile::fake()->image('back.jpg', 1200, 900)],
        'car' => $this->car->ulid,
        ...$data,
    ]);
});

it('shows the trade-in form on any plan', function () {
    $this->actingAs($this->buyer)->get(route('trade-ins.create', [$this->lot->slug, 'car' => $this->car->ulid]))
        ->assertInertia(fn (Assert $page) => $page->component('Deals/TradeIn')->where('car.ulid', $this->car->ulid)->where('maxPhotos', 8));
});

it('takes a trade-in with private, re-encoded photos and captures a lead', function () {
    ($this->submit)()->assertRedirect(route('bookings.index').'#offers');

    $tradeIn = TradeIn::withoutGlobalScopes()->sole();
    expect($tradeIn)
        ->mileage_km->toBe(118_000)
        ->vehicle_id->toBe($this->car->id)
        ->status->toBe(TradeInStatus::Submitted)
        ->and($tradeIn->title())->toBe('2014 Honda Civic')
        ->and($tradeIn->photos)->toHaveCount(2)
        ->and($tradeIn->photos[0])->toStartWith("trade-ins/{$tradeIn->ulid}/")->toEndWith('.webp')
        ->and(Lead::withoutGlobalScopes()->sole()->source)->toBe(LeadSource::TradeIn)
        ->and(Conversation::sole()->messages()->where('side', Message::SYSTEM)->sole()->body)->toBe('Trade-in sent for valuation: 2014 Honda Civic, 118,000 km, 2 photos')
        ->and($this->owner->notifications()->where('data->kind', 'trade_in')->count())->toBe(1);

    Storage::disk('local')->assertExists($tradeIn->photos[0]);

    // Photos only open through a signed link.
    $this->get(route('trade-ins.photo', [$tradeIn, 0]))->assertForbidden();
    $this->get($tradeIn->photoUrls()[0])->assertOk();
    $this->get(URL::temporarySignedRoute('trade-ins.photo', now()->addMinute(), ['tradeIn' => $tradeIn->ulid, 'index' => 5]))->assertNotFound();
});

it('needs photos and a model', function () {
    ($this->submit)(['photos' => []])->assertSessionHasErrors('photos');
    ($this->submit)(['vehicle_model_id' => null])->assertSessionHasErrors('model_name');
    ($this->submit)(['vehicle_model_id' => null, 'model_name' => 'Accord Crosstour'])->assertSessionHasNoErrors();
    expect(TradeIn::withoutGlobalScopes()->sole()->title())->toBe('2014 Honda Accord Crosstour');
});

it('lets the lot send a valuation the buyer hears about on WhatsApp', function () {
    ($this->submit)();
    $tradeIn = TradeIn::withoutGlobalScopes()->sole();

    $this->actingAs($this->owner)->patch(route('dealer.trade-ins.update', [$this->lot, $tradeIn]), ['estimate_low' => '₦4,300,000', 'estimate_high' => '3,800,000'])
        ->assertSessionHasErrors('estimate_high');
    $this->actingAs($this->owner)->patch(route('dealer.trade-ins.update', [$this->lot, $tradeIn]), ['estimate_low' => '₦3,800,000', 'estimate_high' => '₦4,300,000', 'note' => 'Subject to inspection'])
        ->assertSessionHasNoErrors();

    expect($tradeIn->fresh())->status->toBe(TradeInStatus::Valued)->estimate_low->toBe(380_000_000)
        ->and($tradeIn->fresh()->estimate())->toBe('₦3.8m–₦4.3m');

    $sent = $this->whatsapp->to('+2348035550001', 'trade_in_update');
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->params)->toBe(['Ibrahim', 'your 2014 Honda Civic', 'Prime Motors', "It's worth ₦3.8m–₦4.3m. Nothing is binding until they see the car."]);

    $this->actingAs($this->buyer)->get(route('bookings.index'))->assertInertia(fn (Assert $page) => $page->where('tradeIns.0.estimate', '₦3.8m–₦4.3m'));
    $this->actingAs($this->buyer)->post(route('trade-ins.answer', $tradeIn), ['accept' => true])->assertSessionHasNoErrors();
    expect($tradeIn->fresh()->status)->toBe(TradeInStatus::Accepted);
});

it('lets the lot ask for more photos in the chat', function () {
    ($this->submit)();
    $tradeIn = TradeIn::withoutGlobalScopes()->sole();

    $this->actingAs($this->owner)->post(route('dealer.trade-ins.ask-photos', [$this->lot, $tradeIn]))->assertSessionHasNoErrors();
    expect(Message::query()->where('side', Message::LOT)->sole()->body)->toContain('more photos');
});

it('adds a valued trade-in to an order', function () {
    ($this->submit)();
    $tradeIn = TradeIn::withoutGlobalScopes()->sole();
    $this->actingAs($this->owner)->patch(route('dealer.trade-ins.update', [$this->lot, $tradeIn]), ['estimate_low' => '3,800,000', 'estimate_high' => '4,300,000']);

    $this->actingAs($this->owner)->get(route('dealer.manager.orders.create', $this->lot))
        ->assertInertia(fn (Assert $page) => $page->where('tradeIns.0.ulid', $tradeIn->ulid)->where('tradeIns.0.value', 3_800_000));

    $this->car->update(['price' => 1_000_000_000]);

    // A trade-in worth more than the car is refused.
    $this->actingAs($this->owner)->post(route('dealer.manager.orders.store', $this->lot), [
        'vehicle' => $this->car->ulid, 'name' => 'Ibrahim Kabir', 'phone' => '08035550001', 'trade_in' => $tradeIn->ulid, 'trade_in_value' => '11,000,000',
    ])->assertSessionHasErrors('trade_in_value');

    $this->actingAs($this->owner)->post(route('dealer.manager.orders.store', $this->lot), [
        'vehicle' => $this->car->ulid, 'name' => 'Ibrahim Kabir', 'phone' => '08035550001', 'trade_in' => $tradeIn->ulid, 'trade_in_value' => '4,000,000',
    ])->assertSessionHasNoErrors();

    $order = SalesOrder::withoutGlobalScopes()->sole();
    expect($order)->trade_in_id->toBe($tradeIn->id)->trade_in_value->toBe(400_000_000)
        ->and($order->balance)->toBe(600_000_000)
        ->and($tradeIn->fresh()->status)->toBe(TradeInStatus::Accepted);
});

it('links a trade-in visit to the buyer\'s trade-in', function () {
    ($this->submit)();

    $this->actingAs($this->buyer)->post(route('bookings.store'), [
        'lot' => $this->lot->slug,
        'type' => 'trade_in',
        'starts_at' => CarbonImmutable::parse('2026-10-06 10:30', 'Africa/Lagos')->utc()->toIso8601String(),
    ])->assertSessionHasNoErrors();

    expect(TradeIn::withoutGlobalScopes()->sole()->appointment_id)->not->toBeNull();
});

it('keeps trade-ins inside their lot', function () {
    ($this->submit)();
    $tradeIn = TradeIn::withoutGlobalScopes()->sole();
    $this->actingAs(User::factory()->create())->post(route('trade-ins.answer', $tradeIn), ['accept' => true])->assertForbidden();
    $otherOwner = User::factory()->staff()->create();
    $otherLot = app(CreateLot::class)->run($otherOwner, ['name' => 'Other Autos', 'phone' => '+2348021119999']);

    $this->actingAs($otherOwner)->patch(route('dealer.trade-ins.update', [$otherLot, $tradeIn]), ['estimate_low' => '1', 'estimate_high' => '2'])->assertNotFound();
    $this->actingAs($otherOwner)->get(route('dealer.offers.index', [$otherLot, 'tab' => 'trade-ins']))->assertInertia(fn (Assert $page) => $page->has('tradeIns', 0));
});
