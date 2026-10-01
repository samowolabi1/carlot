<?php

namespace App\Domain\System;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * What the server needs for LotLink, checked from inside the app: PHP and its extensions, writable folders, the image
 * repository, the cron job (and the queue it drives on shared hosting), mail, search and the built front end. Used by
 * `php artisan lotlink:doctor` and /admin → System health, so a cPanel install can be checked without SSH.
 */
final class ServerHealth
{
    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** @return list<array{group: string, label: string, status: string, detail: string}> */
    public static function checks(): array
    {
        $checks = [];
        $add = function (string $group, string $label, string $status, string $detail = '') use (&$checks): void {
            $checks[] = compact('group', 'label', 'status', 'detail');
        };
        $production = app()->isProduction();

        // PHP
        $add('PHP', 'PHP version', version_compare(PHP_VERSION, '8.3.0', '>=') ? self::OK : self::FAIL, PHP_VERSION.' (8.3 or newer)');
        foreach (['pdo_mysql', 'mbstring', 'openssl', 'intl', 'gd', 'fileinfo', 'curl', 'xml', 'dom', 'ctype', 'tokenizer', 'iconv', 'zip'] as $ext) {
            $add('PHP', "{$ext} extension", extension_loaded($ext) ? self::OK : self::FAIL, extension_loaded($ext) ? '' : 'Turn it on in cPanel → Select PHP Version → Extensions.');
        }
        foreach (['exif' => 'reads photo orientation', 'gmp' => 'faster web push'] as $ext => $why) {
            $add('PHP', "{$ext} extension", extension_loaded($ext) ? self::OK : self::WARN, extension_loaded($ext) ? '' : "Recommended ({$why}).");
        }
        $webp = function_exists('imagewebp') && (imagetypes() & IMG_WEBP);
        $add('PHP', 'GD can write WebP', $webp ? self::OK : self::FAIL, $webp ? '' : 'Car photos are saved as WebP. Ask the host for GD with WebP support.');
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        $procOpen = function_exists('proc_open') && ! in_array('proc_open', $disabled, true);
        $add('PHP', 'proc_open allowed', $procOpen ? self::OK : self::FAIL, $procOpen ? '' : 'The scheduler starts its tasks with proc_open. Ask the host to allow it, or remove it from disable_functions.');
        $memory = self::bytes((string) ini_get('memory_limit'));
        $add('PHP', 'memory_limit', $memory === -1 || $memory >= 256 * 1024 * 1024 ? self::OK : self::WARN, ini_get('memory_limit').' (256M or more for photo processing)');
        $upload = min(self::bytes((string) ini_get('upload_max_filesize')), self::bytes((string) ini_get('post_max_size')));
        $add('PHP', 'Upload size', $upload >= 12 * 1024 * 1024 ? self::OK : self::WARN, ini_get('upload_max_filesize').' / post '.ini_get('post_max_size').' (12M or more: phone photos)');

        // App
        $add('App', 'APP_KEY set', filled(config('app.key')) ? self::OK : self::FAIL, filled(config('app.key')) ? '' : 'Run php artisan key:generate.');
        $add('App', 'Debug off in production', ! $production || ! config('app.debug') ? self::OK : self::FAIL, $production && config('app.debug') ? 'Set APP_DEBUG=false: debug pages show secrets.' : '');
        $https = str_starts_with((string) config('app.url'), 'https://');
        $add('App', 'APP_URL uses https', $https || ! $production ? self::OK : self::WARN, (string) config('app.url'));
        try {
            DB::select('select 1');
            $add('App', 'Database', self::OK, (string) config('database.default'));
        } catch (Throwable $e) {
            $add('App', 'Database', self::FAIL, $e->getMessage());
        }
        foreach (['storage/app', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $dir) {
            $add('App', "{$dir} writable", is_writable(base_path($dir)) ? self::OK : self::FAIL, is_writable(base_path($dir)) ? '' : 'Set the folder to 755 (owned by your cPanel user).');
        }
        $built = is_file(public_path('build/manifest.json'));
        $add('App', 'Front end built', $built ? self::OK : self::FAIL, $built ? '' : 'Upload public/build from npm run build (the cPanel package includes it).');
        $filament = is_dir(public_path('js/filament'));
        $add('App', 'Admin assets published', $filament ? self::OK : self::WARN, $filament ? '' : 'Run php artisan filament:assets.');

        // Images
        $disk = (string) config('lotlink.media_disk');
        if ($disk === 'public') {
            $add('Images', 'Image repository', self::WARN, 'LOTLINK_MEDIA_DISK=public needs the storage:link symlink. Use media (public/media) instead.');
        } else {
            $driver = (string) config("filesystems.disks.{$disk}.driver");
            if ($driver === 'local') {
                $root = (string) config("filesystems.disks.{$disk}.root");
                $writable = (is_dir($root) || @mkdir($root, 0755, true)) && is_writable($root);
                $add('Images', 'Image repository writable', $writable ? self::OK : self::FAIL, $root);
                $add('Images', 'Image repository URL', self::OK, Storage::disk($disk)->url('vehicles/…'));
                $guard = is_file($root.'/.htaccess');
                $add('Images', 'Scripts blocked in the image folder', $guard ? self::OK : self::WARN, $guard ? '' : 'public/media/.htaccess is missing: re-upload it.');
            } else {
                $add('Images', 'Image repository', self::OK, "{$disk} ({$driver})");
            }
        }

        // Cron and queue
        $beat = Cache::get('lotlink:cron-heartbeat');
        $last = $beat ? Carbon::parse($beat) : null;
        $fresh = $last !== null && $last->gt(now()->subMinutes(3));
        $add('Cron and queue', 'Cron job running', $fresh ? self::OK : self::FAIL, $last ? 'last run '.$last->diffForHumans() : 'never ran. Add the cron job: * * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1');
        $connection = (string) config('queue.default');
        $viaCron = (bool) config('lotlink.queue_via_cron');
        $add('Cron and queue', 'Queue', match (true) {
            $connection === 'sync' => self::WARN,
            $connection === 'database' && ! $viaCron => self::WARN,
            default => self::OK,
        }, match (true) {
            $connection === 'sync' => 'sync: photos and messages are processed during the request (slow). Use database + QUEUE_VIA_CRON=true.',
            $connection === 'database' && ! $viaCron => 'database without a worker: set QUEUE_VIA_CRON=true on shared hosting, or run php artisan queue:work.',
            default => $connection.($viaCron ? ' (worked by the cron job)' : ''),
        });
        if ($connection === 'database') {
            try {
                $pending = DB::table((string) config('queue.connections.database.table', 'jobs'))->count();
                $oldest = DB::table((string) config('queue.connections.database.table', 'jobs'))->min('created_at');
                $stuck = $oldest !== null && (int) $oldest < now()->subMinutes(10)->getTimestamp();
                $add('Cron and queue', 'Jobs waiting', $stuck ? self::WARN : self::OK, $pending.($stuck ? ', the oldest over 10 minutes: check the cron job' : ''));
            } catch (Throwable) {
                $add('Cron and queue', 'Jobs waiting', self::FAIL, 'jobs table missing: run php artisan migrate.');
            }
        }
        try {
            $failed = DB::table('failed_jobs')->count();
            $add('Cron and queue', 'Failed jobs', $failed === 0 ? self::OK : self::WARN, $failed === 0 ? 'none' : "{$failed}: see php artisan queue:failed (retry with queue:retry all)");
        } catch (Throwable) {
            // no failed_jobs table on this connection
        }

        // Services
        $mailer = (string) config('mail.default');
        $add('Services', 'Mail', $mailer === 'log' && $production ? self::FAIL : ($mailer === 'log' ? self::WARN : self::OK), $mailer === 'log' ? 'log: emails (and email sign-in codes) only go to the log. Use smtp with a cPanel email account.' : $mailer);
        $scout = (string) config('scout.driver');
        if ($scout === 'meilisearch') {
            try {
                $up = Http::timeout(3)->get(rtrim((string) config('scout.meilisearch.host'), '/').'/health')->successful();
            } catch (Throwable) {
                $up = false;
            }
            $add('Services', 'Search', $up ? self::OK : self::WARN, $up ? 'Meilisearch' : 'Meilisearch unreachable: searches fall back to MySQL. On cPanel use SCOUT_DRIVER=null.');
        } else {
            $add('Services', 'Search', self::OK, 'MySQL (no search server needed)');
        }
        $broadcast = (string) config('broadcasting.default');
        $add('Services', 'Live chat', self::OK, $broadcast === 'reverb' ? 'Reverb (needs a running reverb:start)' : 'polling every few seconds (no websocket server needed)');
        foreach (['cache.default' => 'Cache', 'session.driver' => 'Sessions'] as $key => $label) {
            $value = (string) config($key);
            $add('Services', $label, $value === 'redis' && ! extension_loaded('redis') ? self::FAIL : self::OK, $value);
        }
        $add('Services', 'WhatsApp', config('lotlink.whatsapp_driver') === 'log' && $production ? self::WARN : self::OK, (string) config('lotlink.whatsapp_driver'));

        return $checks;
    }

    /** @return array{ok: int, warn: int, fail: int} */
    public static function summary(): array
    {
        $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        foreach (self::checks() as $c) {
            $counts[$c['status']]++;
        }

        return $counts;
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
