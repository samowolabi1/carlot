<?php

namespace App\Domain\Lots\Actions;

use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotHour;
use Illuminate\Support\Facades\DB;

class SaveLotHours
{
    /**
     * @param  array{days: list<array{weekday: int, is_closed: bool, opens_at: ?string, closes_at: ?string}>, slot_minutes: int, slot_capacity: int}  $data
     */
    public function run(Lot $lot, array $data): void
    {
        DB::transaction(function () use ($lot, $data): void {
            foreach ($data['days'] as $day) {
                LotHour::withoutGlobalScopes()->updateOrCreate(
                    ['lot_id' => $lot->getKey(), 'weekday' => $day['weekday']],
                    [
                        'is_closed' => $day['is_closed'],
                        'opens_at' => $day['is_closed'] ? null : $day['opens_at'],
                        'closes_at' => $day['is_closed'] ? null : $day['closes_at'],
                        'slot_minutes' => $data['slot_minutes'],
                        'slot_capacity' => $data['slot_capacity'],
                    ],
                );
            }
        });
    }

    /** Mon–Fri 08:00–18:00, Sat 09:00–16:00, Sun closed (as in the settings design). */
    public static function defaults(): array
    {
        $days = [];

        foreach (range(0, 6) as $weekday) {
            $days[] = match ($weekday) {
                0 => ['weekday' => 0, 'is_closed' => true, 'opens_at' => null, 'closes_at' => null],
                6 => ['weekday' => 6, 'is_closed' => false, 'opens_at' => '09:00', 'closes_at' => '16:00'],
                default => ['weekday' => $weekday, 'is_closed' => false, 'opens_at' => '08:00', 'closes_at' => '18:00'],
            };
        }

        return ['days' => $days, 'slot_minutes' => 30, 'slot_capacity' => 2];
    }
}
