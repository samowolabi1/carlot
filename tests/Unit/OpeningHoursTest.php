<?php

use App\Domain\Lots\Models\LotHour;
use App\Domain\Marketplace\Support\OpeningHours;
use Carbon\CarbonImmutable;

function hours(): OpeningHours
{
    $rows = collect(range(0, 6))->map(fn ($d) => new LotHour([
        'weekday' => $d,
        'is_closed' => $d === 0,
        'opens_at' => $d === 0 ? null : ($d === 6 ? '09:00:00' : '08:00:00'),
        'closes_at' => $d === 0 ? null : ($d === 6 ? '16:00:00' : '18:00:00'),
    ]));

    return new OpeningHours($rows, 'Africa/Lagos');
}

it('says when a lot is open or when it opens next, in lot time', function (string $utc, bool $open, string $label) {
    expect(hours()->status(CarbonImmutable::parse($utc, 'UTC')))->toBe(['open' => $open, 'label' => $label]);
})->with([
    'Monday 10am Lagos' => ['2026-09-28 09:00', true, 'Open until 6pm'],
    'Monday 7am Lagos' => ['2026-09-28 06:00', false, 'Closed · opens 8am'],
    'Saturday 5pm Lagos' => ['2026-10-03 16:00', false, 'Closed · opens Mon 8am'],
    'Sunday noon Lagos' => ['2026-10-04 11:00', false, 'Closed · opens tomorrow 8am'],
]);

it('groups days with the same hours', function () {
    expect(hours()->table())->toBe([
        ['days' => 'Mon–Fri', 'hours' => '8:00am – 6:00pm'],
        ['days' => 'Sat', 'hours' => '9:00am – 4:00pm'],
        ['days' => 'Sun', 'hours' => 'Closed'],
    ]);
});
