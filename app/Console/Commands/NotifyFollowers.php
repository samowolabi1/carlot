<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Notifications\NewStockAtLot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Every 30 minutes (TDD NotifyFollowers): one message per lot to its followers about the
 * cars it listed since the last one, instead of a message per car.
 */
class NotifyFollowers extends Command
{
    protected $signature = 'followers:notify';

    protected $description = 'Tell followers about new stock, batched per lot';

    public function handle(): int
    {
        $sent = 0;

        Lot::query()->where('status', LotStatus::Active)->whereHas('followers')->each(function (Lot $lot) use (&$sent): void {
            // New followers only hear about cars listed after they followed (pivot created_at).
            $since = $lot->followers_notified_at ?? now()->subMinutes(30);

            $cars = Vehicle::withoutGlobalScopes()->marketplace()->where('vehicles.lot_id', $lot->id)
                ->where('vehicles.listed_at', '>', $since)
                ->with(['make', 'model'])
                ->orderByDesc('vehicles.listed_at')
                ->get();

            $lot->forceFill(['followers_notified_at' => now()])->save();

            if ($cars->isEmpty()) {
                return;
            }

            $followers = $lot->followers()->wherePivot('created_at', '<', $cars->max('listed_at'))->get();
            Notification::send($followers, new NewStockAtLot($lot, $cars->count(), $cars->first()->title()));
            $sent += $followers->count();
        });

        $this->info("Notified {$sent} followers.");

        return self::SUCCESS;
    }
}
