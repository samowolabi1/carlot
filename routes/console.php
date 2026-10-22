<?php

use App\Domain\System\ScheduledTask;
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

// Commands go through ScheduledTask: as separate processes normally, inside the cron job's own process where the host
// disables proc_open (many cPanel servers), so they still run there.

// Appointments (TDD: Scheduled tasks). Needs `php artisan schedule:work` locally, or a
// cron entry for `php artisan schedule:run` every minute in production.
ScheduledTask::command('appointments:remind')->everyFiveMinutes()->withoutOverlapping();
ScheduledTask::command('appointments:mark-no-shows')->everyTenMinutes()->withoutOverlapping();
ScheduledTask::command('appointments:escalate-pending')->hourly()->withoutOverlapping();
ScheduledTask::command('otp:prune')->daily();
ScheduledTask::command('engagement:run')->hourly()->withoutOverlapping();
ScheduledTask::command('engagement:send-broadcasts')->everyMinute()->withoutOverlapping();
ScheduledTask::command('sanctum:prune-expired --hours=24')->daily();

// Sales Manager (TDD M19)
ScheduledTask::command('manager:follow-up-reminders')->everyFifteenMinutes()->withoutOverlapping();
ScheduledTask::command('share-links:prune')->daily();
ScheduledTask::command('finance:prune')->dailyAt('03:30')->withoutOverlapping();

// Billing and spotlight (TDD M16, M5)
ScheduledTask::command('subscriptions:enforce-limits')->dailyAt('02:00')->withoutOverlapping();
ScheduledTask::command('spotlights:expire')->hourly()->withoutOverlapping();
ScheduledTask::command('followers:notify')->everyThirtyMinutes()->withoutOverlapping();

// Leads and chat (TDD M11)
ScheduledTask::command('leads:follow-up-reminders')->everyFifteenMinutes()->withoutOverlapping();
ScheduledTask::command('chat:notify-unread')->everyFiveMinutes()->withoutOverlapping();

// Offers, trade-ins and reservations (TDD M12)
ScheduledTask::command('offers:expire')->hourly()->withoutOverlapping();
ScheduledTask::command('reservations:expire')->everyFiveMinutes()->withoutOverlapping();
ScheduledTask::command('appointments:release-unpaid')->everyFiveMinutes()->withoutOverlapping();

// Sales Manager Pro (TDD M19). Hourly so each seller gets them at its own local time.
ScheduledTask::command('manager:instalment-reminders')->hourly()->withoutOverlapping();
ScheduledTask::command('manager:mark-overdue')->hourlyAt(30)->withoutOverlapping();
ScheduledTask::command('manager:daily-summary')->hourly()->withoutOverlapping();

// Location and analytics (TDD M8, M15)
ScheduledTask::command('stats:rollup')->hourly()->withoutOverlapping();
ScheduledTask::command('location:end-expired')->everyMinute()->withoutOverlapping();

// Trust (TDD M14)
ScheduledTask::command('reviews:invite')->everyFifteenMinutes()->withoutOverlapping();

// SEO (TDD M18)
ScheduledTask::command('sitemap:generate')->dailyAt('03:00')->withoutOverlapping();

// Privacy (TDD: account deletion)
ScheduledTask::command('accounts:anonymise')->dailyAt('02:30')->withoutOverlapping();

// Horizon's dashboard graphs (Redis queue only).
if (config('queue.default') === 'redis') {
    ScheduledTask::command('horizon:snapshot')->everyFiveMinutes();
}

// cPanel / shared hosting (QUEUE_VIA_CRON=true): no always-on worker, so each minute's cron run works through the queue
// for up to ~50 seconds and stops when it is empty. It's last so the tasks above run first. Long jobs (broadcasts)
// finish even if they run past that; withoutOverlapping keeps the next minute's run from starting a second worker.
if (config('lotlink.queue_via_cron')) {
    ScheduledTask::command('queue:work', [
        '--queue='.config('lotlink.queue_names'), '--stop-when-empty', '--max-time=50', '--tries=3', '--sleep=1', '--memory=256',
    ])->everyMinute()->withoutOverlapping(20)->name('queue-via-cron');
}
