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
