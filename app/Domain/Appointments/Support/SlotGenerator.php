<?php

namespace App\Domain\Appointments\Support;

use App\Domain\Appointments\Models\Appointment;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotClosure;
use App\Domain\Lots\Models\LotHour;
use Carbon\CarbonImmutable;

/**
 * Bookable slots (TDD M7): the lot's opening hours cut into slot_minutes pieces, minus
 * closures, slots already at slot_capacity, past times and anything inside the lot's
 * minimum notice. Worked out in the lot's timezone; starts_at values are UTC.
 */
class SlotGenerator
{
    public const DAYS_AHEAD = 14;

    /**
     * @return list<array{date: string, weekday: string, day: int, month: string, closed: bool, slots: list<array{time: string, starts_at: string, remaining: int, available: bool}>}>
     */
    public function days(Lot $lot, ?CarbonImmutable $now = null, int $days = self::DAYS_AHEAD, ?Appointment $ignore = null): array
    {
        $tz = $lot->timezone;
        $now = ($now ?? CarbonImmutable::now())->setTimezone($tz);
        $earliest = $now->addMinutes($lot->booking_min_notice_minutes);
        $firstDay = $now->startOfDay();
        $lastDay = $firstDay->addDays($days);

        $hours = LotHour::withoutGlobalScopes()->where('lot_id', $lot->id)->get()->keyBy('weekday');
        $closures = LotClosure::withoutGlobalScopes()->where('lot_id', $lot->id)
            ->whereBetween('date', [$firstDay->toDateString(), $lastDay->toDateString()])
            ->pluck('date')->map(fn ($d) => $d->toDateString())->all();

        $booked = Appointment::withoutGlobalScopes()
            ->where('lot_id', $lot->id)
            ->holdingSlot()
            ->whereBetween('starts_at', [$firstDay->utc(), $lastDay->utc()])
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
            ->get(['starts_at'])
            ->countBy(fn (Appointment $a) => $a->starts_at->getTimestamp());

        $result = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $firstDay->addDays($i);
            $row = $hours->get($date->dayOfWeek);
            $closed = ! $row || $row->is_closed || ! $row->opens_at || ! $row->closes_at || in_array($date->toDateString(), $closures, true);
            $slots = [];

            if (! $closed) {
                $start = $date->setTimeFromTimeString($row->opens_at);
                $close = $date->setTimeFromTimeString($row->closes_at);
                $length = max(5, $row->slot_minutes);

                for ($t = $start; $t->addMinutes($length)->lte($close); $t = $t->addMinutes($length)) {
                    $remaining = max(0, $row->slot_capacity - ($booked[$t->getTimestamp()] ?? 0));
                    $slots[] = [
                        'time' => $t->format('H:i'),
                        'starts_at' => $t->utc()->toIso8601String(),
                        'remaining' => $remaining,
                        'available' => $remaining > 0 && $t->gte($earliest),
                    ];
                }
            }

            $result[] = [
                'date' => $date->toDateString(),
                'weekday' => $date->format('D'),
                'day' => $date->day,
                'month' => $date->format('F Y'),
                'closed' => $closed,
                'slots' => $slots,
            ];
        }

        return $result;
    }

    /**
     * The slot that starts exactly at $start, if it can be booked now, with its length.
     *
     * @return array{starts_at: CarbonImmutable, minutes: int}|null
     */
    public function find(Lot $lot, CarbonImmutable $start, ?Appointment $ignore = null, ?CarbonImmutable $now = null): ?array
    {
        $local = $start->setTimezone($lot->timezone);
        $now ??= CarbonImmutable::now();
        $daysAhead = (int) $now->setTimezone($lot->timezone)->startOfDay()->diffInDays($local->startOfDay());

        if ($daysAhead < 0 || $daysAhead >= self::DAYS_AHEAD) {
            return null;
        }

        foreach ($this->days($lot, $now, $daysAhead + 1, $ignore)[$daysAhead]['slots'] as $slot) {
            if ($slot['available'] && CarbonImmutable::parse($slot['starts_at'])->equalTo($start)) {
                $minutes = LotHour::withoutGlobalScopes()->where('lot_id', $lot->id)->where('weekday', $local->dayOfWeek)->value('slot_minutes');

                return ['starts_at' => $start->utc(), 'minutes' => (int) ($minutes ?? 30)];
            }
        }

        return null;
    }
}
