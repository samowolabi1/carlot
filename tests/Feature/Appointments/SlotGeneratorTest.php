<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Support\SlotGenerator;
use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Models\LotClosure;
use Carbon\CarbonImmutable;

beforeEach(function () {
    // Monday 5 Oct 2026, 09:00 in Lagos (08:00 UTC). Default hours: Mon–Fri 8–18, Sat 9–16,
    // Sun closed, 30-minute slots, 2 visits per slot, 2 hours' notice.
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC'));
    $owner = User::factory()->create();
    $this->lot = app(CreateLot::class)->run($owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $this->lot->update(['status' => 'active']);
    $this->slots = app(SlotGenerator::class);
});

it('cuts opening hours into slots for the next 14 days, in lot time', function () {
    $days = $this->slots->days($this->lot->fresh());

    expect($days)->toHaveCount(14)
        ->and($days[0])->date->toBe('2026-10-05')->weekday->toBe('Mon')->closed->toBeFalse()
        ->and($days[0]['slots'])->toHaveCount(20)
        ->and($days[0]['slots'][0])->toMatchArray(['time' => '08:00', 'starts_at' => '2026-10-05T07:00:00+00:00'])
        ->and($days[5]['slots'])->toHaveCount(14)          // Saturday 9–16
        ->and($days[6]['closed'])->toBeTrue();             // Sunday
});

it('hides past times and anything inside the minimum notice', function () {
    $today = collect($this->slots->days($this->lot->fresh())[0]['slots'])->keyBy('time');

    expect($today['10:30']['available'])->toBeFalse()   // 09:00 now + 2h notice = 11:00
        ->and($today['11:00']['available'])->toBeTrue();
});

it('closes days on the seller\'s closure list', function () {
    LotClosure::withoutGlobalScopes()->create(['lot_id' => $this->lot->id, 'date' => '2026-10-06', 'reason' => 'Stock-taking']);

    expect($this->slots->days($this->lot->fresh())[1])->closed->toBeTrue()->slots->toBe([]);
});

it('fills a slot at its capacity, ignoring cancelled bookings', function () {
    $start = CarbonImmutable::parse('2026-10-06 10:00', 'Africa/Lagos');
    Appointment::factory()->count(2)->at($start)->create(['lot_id' => $this->lot->id]);
    Appointment::factory()->at($start)->create(['lot_id' => $this->lot->id, 'status' => 'cancelled']);

    $slot = collect($this->slots->days($this->lot->fresh())[1]['slots'])->firstWhere('time', '10:00');

    expect($slot)->remaining->toBe(0)->available->toBeFalse()
        ->and($this->slots->find($this->lot->fresh(), $start->utc()))->toBeNull();
});

it('only finds real slot starts', function () {
    $lot = $this->lot->fresh();

    expect($this->slots->find($lot, CarbonImmutable::parse('2026-10-06 10:00', 'Africa/Lagos')))->not->toBeNull()
        ->and($this->slots->find($lot, CarbonImmutable::parse('2026-10-06 10:15', 'Africa/Lagos')))->toBeNull()
        ->and($this->slots->find($lot, CarbonImmutable::parse('2026-10-11 10:00', 'Africa/Lagos')))->toBeNull()  // Sunday
        ->and($this->slots->find($lot, CarbonImmutable::parse('2026-10-25 10:00', 'Africa/Lagos')))->toBeNull(); // beyond 14 days
});
