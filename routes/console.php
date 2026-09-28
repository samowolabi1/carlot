<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Appointments (TDD: Scheduled tasks). Needs `php artisan schedule:work` locally, or a
// cron entry for `php artisan schedule:run` every minute in production.
Schedule::command('appointments:remind')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('appointments:mark-no-shows')->everyTenMinutes()->withoutOverlapping();
Schedule::command('appointments:escalate-pending')->hourly()->withoutOverlapping();
Schedule::command('otp:prune')->daily();

// Lot Manager (TDD M19)
Schedule::command('manager:follow-up-reminders')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('share-links:prune')->daily();

// Billing and spotlight (TDD M16, M5)
Schedule::command('subscriptions:enforce-limits')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('spotlights:expire')->hourly()->withoutOverlapping();
Schedule::command('followers:notify')->everyThirtyMinutes()->withoutOverlapping();

// Leads and chat (TDD M11)
Schedule::command('leads:follow-up-reminders')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('chat:notify-unread')->everyFiveMinutes()->withoutOverlapping();

// Offers, trade-ins and reservations (TDD M12)
Schedule::command('offers:expire')->hourly()->withoutOverlapping();
Schedule::command('reservations:expire')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('appointments:release-unpaid')->everyFiveMinutes()->withoutOverlapping();
