<?php

use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotHour;
use App\Domain\Lots\Models\LotInvitation;
use App\Domain\Lots\Support\CurrentLot;

it('blocks members of one lot from another lot', function (string $routeName, string $method) {
    $lotA = Lot::factory()->create();
    $lotB = Lot::factory()->create();

    $this->actingAs($lotA->owner)
        ->{$method}(route($routeName, $lotB))
        ->assertForbidden();
})->with([
    ['dealer.dashboard', 'get'],
    ['dealer.settings', 'get'],
    ['dealer.staff', 'get'],
    ['dealer.onboarding.submit', 'post'],
    ['dealer.settings.location', 'put'],
    ['dealer.staff.invite', 'post'],
]);

it('scopes lot-owned models to the current lot', function () {
    $lotA = Lot::factory()->create();
    $lotB = Lot::factory()->create();

    foreach ([$lotA, $lotB] as $lot) {
        LotHour::withoutGlobalScopes()->create(['lot_id' => $lot->id, 'weekday' => 1, 'opens_at' => '08:00', 'closes_at' => '18:00']);
    }

    app(CurrentLot::class)->set($lotA);

    expect(LotHour::count())->toBe(1)
        ->and(LotHour::first()->lot_id)->toBe($lotA->id);

    // New rows are stamped with the current lot.
    $closure = $lotA->closures()->make(['date' => '2026-12-25']);
    $closure->lot_id = null;
    $closure->save();
    expect($closure->lot_id)->toBe($lotA->id);
});

it('does not let one lot touch another lot\'s invitations', function () {
    $lotA = Lot::factory()->create();
    $lotB = Lot::factory()->create();
    $invite = LotInvitation::withoutGlobalScopes()->create([
        'lot_id' => $lotB->id, 'phone_or_email' => '+2348031112222', 'role' => 'sales', 'token' => 'x', 'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($lotA->owner)
        ->delete(route('dealer.staff.invitations.cancel', [$lotA, $invite->id]))
        ->assertNotFound();

    expect(LotInvitation::withoutGlobalScopes()->find($invite->id))->not->toBeNull();
});
