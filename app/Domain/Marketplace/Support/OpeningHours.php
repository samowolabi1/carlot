<?php

namespace App\Domain\Marketplace\Support;

use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotHour;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * "Open until 6pm" / "Closed · opens Mon 8am", in the lot's own timezone.
 */
final class OpeningHours
{
    /** @param Collection<int, LotHour> $hours */
    public function __construct(private readonly Collection $hours, private readonly string $timezone) {}

    public static function for(Lot $lot): self
    {
        return new self($lot->hours()->withoutGlobalScopes()->get(), $lot->timezone);
    }

    /** @return array{open: bool, label: string}|null null when the lot hasn't set hours */
    public function status(?CarbonImmutable $at = null): ?array
    {
        if ($this->hours->isEmpty()) {
            return null;
        }

        $now = ($at ?? CarbonImmutable::now())->setTimezone($this->timezone);
        $today = $this->hours->firstWhere('weekday', $now->dayOfWeek);

        if ($today && ! $today->is_closed && $today->opens_at && $today->closes_at) {
            $opens = $now->setTimeFromTimeString($today->opens_at);
            $closes = $now->setTimeFromTimeString($today->closes_at);

            if ($now->betweenIncluded($opens, $closes)) {
                return ['open' => true, 'label' => 'Open until '.self::time($closes)];
            }

            if ($now->lt($opens)) {
                return ['open' => false, 'label' => 'Closed · opens '.self::time($opens)];
            }
        }

        for ($i = 1; $i <= 7; $i++) {
            $day = $now->addDays($i);
            $hours = $this->hours->firstWhere('weekday', $day->dayOfWeek);

            if ($hours && ! $hours->is_closed && $hours->opens_at) {
                $label = $i === 1 ? 'tomorrow' : $day->format('D');

                return ['open' => false, 'label' => "Closed · opens {$label} ".self::time($day->setTimeFromTimeString($hours->opens_at))];
            }
        }

        return ['open' => false, 'label' => 'Closed'];
    }

    /** @return list<array{days: string, hours: string}> grouped for display: "Mon–Fri 8:00am – 6:00pm" */
    public function table(): array
    {
        $order = [1, 2, 3, 4, 5, 6, 0];
        $rows = [];

        foreach ($order as $weekday) {
            $h = $this->hours->firstWhere('weekday', $weekday);
            $text = ! $h || $h->is_closed || ! $h->opens_at || ! $h->closes_at
                ? 'Closed'
                : self::time(CarbonImmutable::parse($h->opens_at), true).' – '.self::time(CarbonImmutable::parse($h->closes_at), true);
            $name = substr(LotHour::WEEKDAYS[$weekday], 0, 3);
            $last = array_key_last($rows);

            if ($last !== null && $rows[$last]['hours'] === $text) {
                $rows[$last]['to'] = $name;
            } else {
                $rows[] = ['from' => $name, 'to' => null, 'hours' => $text];
            }
        }

        return array_map(fn (array $r) => ['days' => $r['to'] ? "{$r['from']}–{$r['to']}" : $r['from'], 'hours' => $r['hours']], $rows);
    }

    private static function time(CarbonImmutable $t, bool $withMinutes = false): string
    {
        return $withMinutes || $t->minute !== 0 ? $t->format('g:ia') : $t->format('ga');
    }
}
