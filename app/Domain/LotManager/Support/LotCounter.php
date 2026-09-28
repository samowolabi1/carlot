<?php

namespace App\Domain\LotManager\Support;

use App\Domain\Lots\Models\Lot;
use Illuminate\Support\Facades\DB;

/**
 * Per-lot running numbers for orders and receipts: "PM-2026-00042". Call inside a
 * transaction; the counter row is locked until it commits, so numbers never repeat.
 */
final class LotCounter
{
    public static function next(Lot $lot, string $name): string
    {
        $year = now($lot->timezone)->year;
        $key = "{$name}:{$year}";

        DB::table('lot_counters')->insertOrIgnore(['lot_id' => $lot->id, 'name' => $key, 'value' => 0]);
        $row = DB::table('lot_counters')->where('lot_id', $lot->id)->where('name', $key)->lockForUpdate()->first();
        $value = (int) $row->value + 1;
        DB::table('lot_counters')->where('lot_id', $lot->id)->where('name', $key)->update(['value' => $value]);

        return sprintf('%s-%d-%05d', self::code($lot), $year, $value);
    }

    /** "Prime Motors" → "PM"; single words give three letters ("Autoworld" → "AUT"). */
    public static function code(Lot $lot): string
    {
        $initials = $lot->initials();

        return strlen($initials) >= 2 ? $initials : strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $lot->name) ?: 'LOT', 0, 3));
    }
}
