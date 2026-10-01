<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Proof the cron job runs (shown in /admin → System health and `php artisan lotlink:doctor`). In-process, so it works
// even where the host blocks starting processes.
Schedule::call(fn () => Cache::forever('lotlink:cron-heartbeat', now()->toIso8601String()))->everyMinute()->name('cron-heartbeat');

// Appointments (TDD: Scheduled tasks). Needs `php artisan schedule:work` locally, or a
// cron entry for `php artisan schedule:run` every minute in production.
Schedule::command('appointments:remind')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('appointments:mark-no-shows')->everyTenMinutes()->withoutOverlapping();
Schedule::command('appointments:escalate-pending')->hourly()->withoutOverlapping();
Schedule::command('otp:prune')->daily();
Schedule::command('engagement:run')->hourly()->withoutOverlapping();
Schedule::command('engagement:send-broadcasts')->everyMinute()->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Lot Manager (TDD M19)
Schedule::command('manager:follow-up-reminders')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('share-links:prune')->daily();
Schedule::command('finance:prune')->dailyAt('03:30')->withoutOverlapping();

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

// Lot Manager Pro (TDD M19). Hourly so each lot gets them at its own local time.
Schedule::command('manager:instalment-reminders')->hourly()->withoutOverlapping();
Schedule::command('manager:mark-overdue')->hourlyAt(30)->withoutOverlapping();
Schedule::command('manager:daily-summary')->hourly()->withoutOverlapping();

// Location and analytics (TDD M8, M15)
Schedule::command('stats:rollup')->hourly()->withoutOverlapping();
Schedule::command('location:end-expired')->everyMinute()->withoutOverlapping();

// Trust (TDD M14)
Schedule::command('reviews:invite')->everyFifteenMinutes()->withoutOverlapping();

// SEO (TDD M18)
Schedule::command('sitemap:generate')->dailyAt('03:00')->withoutOverlapping();

// Privacy (TDD: account deletion)
Schedule::command('accounts:anonymise')->dailyAt('02:30')->withoutOverlapping();

// Horizon's dashboard graphs (Redis queue only).
if (config('queue.default') === 'redis') {
    Schedule::command('horizon:snapshot')->everyFiveMinutes();
}

// cPanel / shared hosting (QUEUE_VIA_CRON=true): no always-on worker, so each minute's cron run works through the queue
// for up to ~50 seconds and stops when it is empty. It's last so the tasks above run first. Long jobs (broadcasts)
// finish even if they run past that; withoutOverlapping keeps the next minute's run from starting a second worker.
if (config('lotlink.queue_via_cron')) {
    Schedule::command('queue:work', [
        '--queue='.config('lotlink.queue_names'), '--stop-when-empty', '--max-time=50', '--tries=3', '--sleep=1', '--memory=256',
    ])->everyMinute()->withoutOverlapping(20)->name('queue-via-cron');
}
