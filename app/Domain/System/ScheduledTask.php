<?php

namespace App\Domain\System;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/**
 * Scheduled artisan commands that still run where the host disables proc_open (common on cPanel). Laravel starts each
 * Schedule::command() as a separate process; without proc_open every one of them fails while the cron job looks fine.
 * Here they run inside the cron job's own PHP process instead (named, so withoutOverlapping keeps working).
 * `lotlink.schedule_in_process`: null = decide from the server, true/false to force.
 */
final class ScheduledTask
{
    /** @param  array<int|string, mixed>  $parameters */
    public static function command(string $command, array $parameters = []): Event
    {
        if (! self::inProcess()) {
            return Schedule::command($command, $parameters);
        }

        $line = self::commandLine($command, $parameters);

        return Schedule::call(fn () => Artisan::call($line))->name($command);
    }

    /**
     * One command line, as Schedule::command() would build it: Artisan::call() ignores list-style options
     * (['--queue=default', '--stop-when-empty']), which left the in-process queue worker without its settings.
     *
     * @param  array<int|string, mixed>  $parameters
     */
    public static function commandLine(string $command, array $parameters): string
    {
        $parts = [$command];
        foreach ($parameters as $key => $value) {
            if (is_int($key)) {
                $parts[] = self::quote((string) $value);
            } elseif ($value === true) {
                $parts[] = $key;
            } elseif ($value !== false && $value !== null) {
                $parts[] = $key.'='.self::quote((string) $value);
            }
        }

        return implode(' ', $parts);
    }

    private static function quote(string $value): string
    {
        return preg_match('/[\s"\']/', $value) ? '"'.str_replace('"', '\\"', $value).'"' : $value;
    }

    public static function inProcess(): bool
    {
        $forced = config('lotlink.schedule_in_process');

        return $forced === null ? ! self::procOpenAllowed() : (bool) $forced;
    }

    public static function procOpenAllowed(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('proc_open') && ! in_array('proc_open', $disabled, true);
    }
}
