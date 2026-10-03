<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Actions\CreateLot;
use Carbon\CarbonImmutable;

/*
 * Each form checks what kind of data it gets, not just that something was sent: names are letters,
 * phones are dialable numbers, money is whole naira, and nothing is longer than its column.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'UTC'));
    $this->owner = User::factory()->staff()->create(['phone' => '+2348020000001']);
    $this->lot = app(CreateLot::class)->run($this->owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->car = Vehicle::factory()->available()->create(['lot_id' => $this->lot->id, 'price' => 1_000_000_000]);
});

it('checks the name on registration', function () {
    $user = User::factory()->create(['name' => null]);

    $this->actingAs($user)->put(route('profile.name.update'), ['name' => 'Chi0ma $$'])
        ->assertSessionHasErrors(['name' => 'Name can only contain letters, spaces, hyphens and apostrophes.']);
    $this->actingAs($user)->put(route('profile.name.update'), ['name' => str_repeat('a', 81)])->assertSessionHasErrors('name');
    $this->actingAs($user)->put(route('profile.name.update'), ['name' => "Chioma O'Neil-Adé"])->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe("Chioma O'Neil-Adé");
});

it('checks phone numbers and emails before sending a sign-in code', function () {
    $this->post(route('login.send'), ['phone' => 'abc'])->assertSessionHasErrors(['phone' => 'Phone number must look like 0803 123 4567 or +234 803 123 4567.']);
    $this->post(route('login.send'), ['phone' => '0803 12'])->assertSessionHasErrors('phone');
    $this->post(route('login.send'), ['phone' => str_repeat('1', 40)])->assertSessionHasErrors('phone');
    $this->post(route('login.send'), ['email' => 'ada@example', 'method' => 'email'])->assertSessionHasErrors('email');

    expect($this->whatsapp->sent)->toBeEmpty();
});

it('checks the seller profile: a business name, real phone numbers and an email', function () {
    $save = fn (array $data) => $this->actingAs($this->owner)->put(route('dealer.settings.profile', $this->lot), [
        'name' => 'Prime Motors', 'phone' => '0802 111 2233', ...$data,
    ]);

    $save(['name' => '!!!'])->assertSessionHasErrors('name');
    $save(['name' => '12345'])->assertSessionHasErrors('name');
    $save(['phone' => '123'])->assertSessionHasErrors('phone');
    $save(['whatsapp' => '0803 abc 4567'])->assertSessionHasErrors('whatsapp');
    $save(['email' => 'sales@prime'])->assertSessionHasErrors('email');
    $save(['name' => 'Prime Motors & Sons (Ikeja)', 'whatsapp' => '+234 803 123 4567', 'email' => 'sales@prime.ng'])->assertSessionHasNoErrors();

    expect($this->lot->fresh())->name->toBe('Prime Motors & Sons (Ikeja)')->whatsapp->toBe('+2348031234567');
});

it('checks walk-in names, phones and budgets', function () {
    $walkIn = fn (array $data) => $this->actingAs($this->owner)->post(route('dealer.manager.walk-ins.store', $this->lot), [
        'name' => 'Ada Obi', 'phone' => '0803 555 0101', ...$data,
    ]);

    $walkIn(['name' => '12345'])->assertSessionHasErrors('name');
    $walkIn(['phone' => '0803'])->assertSessionHasErrors('phone');
    $walkIn(['budget_max' => '9,000,000.50'])->assertSessionHasErrors(['budget_max' => 'Budget must be a whole number, with no letters or kobo.']);
    $walkIn(['budget_max' => '₦900,000,000,000'])->assertSessionHasErrors('budget_max');
    $walkIn(['budget_max' => '₦9,000,000'])->assertSessionHasNoErrors();

    expect(LotCustomer::withoutGlobalScopes()->sole()->budget_max)->toBe(900_000_000); // kobo
});

it('never turns kobo into naira when a payment is typed with a decimal point', function () {
    $this->actingAs($this->owner)->post(route('dealer.manager.orders.store', $this->lot), [
        'vehicle' => $this->car->ulid, 'name' => 'Bola Ade', 'phone' => '08035550202', 'agreed_price' => '₦10,000,000',
    ])->assertSessionHasNoErrors();
    $order = SalesOrder::withoutGlobalScopes()->sole();
    $pay = fn (string $amount, array $data = []) => $this->actingAs($this->owner)
        ->post(route('dealer.manager.orders.payments.store', [$this->lot, $order]), ['amount' => $amount, 'method' => 'transfer', ...$data]);

    $pay('1500.50')->assertSessionHasErrors('amount');
    $pay('15o0')->assertSessionHasErrors('amount');
    $pay('₦1,500', ['reference' => '<script>'])->assertSessionHasErrors('reference');
    expect(OrderPayment::withoutGlobalScopes()->count())->toBe(0);

    $pay('₦1,500,000.00', ['reference' => 'TRF/2026/00341'])->assertSessionHasNoErrors();
    expect(OrderPayment::withoutGlobalScopes()->sole()->amount)->toBe(150_000_000);
});

it('rejects amounts too big for the database instead of failing', function () {
    $this->actingAs($this->owner)->post(route('dealer.manager.orders.store', $this->lot), [
        'vehicle' => $this->car->ulid, 'name' => 'Bola Ade', 'phone' => '08035550202', 'agreed_price' => '99999999999999999999',
    ])->assertSessionHasErrors('agreed_price');

    $this->actingAs($this->owner)->put(route('dealer.vehicles.price', [$this->lot, $this->car]), ['price' => '₦9,000,000,000'])
        ->assertSessionHasErrors('price');
    $this->actingAs($this->owner)->put(route('dealer.vehicles.price', [$this->lot, $this->car]), ['price' => '₦12,500,000'])
        ->assertSessionHasNoErrors();

    expect($this->car->fresh()->price)->toBe(1_250_000_000);
});

it('checks model names on the add-car form', function () {
    $draft = Vehicle::factory()->create(['lot_id' => $this->lot->id]);
    $identity = fn (array $data) => $this->actingAs($this->owner)->put(route('dealer.vehicles.identity', [$this->lot, $draft]), [
        'make_id' => $draft->make_id, 'model_name' => 'Camry', 'year' => 2018, ...$data,
    ]);

    $identity(['vehicle_model_id' => null, 'model_name' => '<script>alert(1)</script>'])->assertSessionHasErrors('model_name');
    $identity(['vehicle_model_id' => null, 'model_name' => 'C-Class', 'trim' => 'E 350 4Matic'])->assertSessionHasNoErrors();
});
