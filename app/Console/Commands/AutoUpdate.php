<?php

namespace App\Console\Commands;

use App\Domain\Admin\Actions\EnsureFirstAdmin;
use App\Domain\Lots\Models\Plan;
use App\Domain\System\Actions\LoadDemoData;
use App\Domain\System\AppVersion;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * The cron job's helper on cPanel without a terminal (see AppVersion); schedule:run calls it first when there's work:
 *  - a new package (VERSION differs from the last deployed one): APP_KEY if empty, lotlink:deploy (with --seed on a first
 *    install), then the version is recorded; a failed update is retried each minute while the site shows "updating";
 *  - .env was saved: the settings are re-cached;
 *  - storage/app/load-demo-data: the demo data is loaded once (LoadDemoData);
 *  - and each time, the first admin is created or fixed from ADMIN_EMAIL / ADMIN_PASSWORD, or reset on request (EnsureFirstAdmin).
 * Everything is written to storage/logs/auto-update.log, which can be read in cPanel's File Manager.
 */
class AutoUpdate extends Command
{
    protected $signature = 'lotlink:auto-update';

    protected $description = 'Finish an uploaded package: migrate (install the first time), cache and record the version';

    public function handle(): int
    {
        $base = $this->base();
        $version = AppVersion::current($base);
        $pending = AppVersion::pending($base);
        $envChanged = AppVersion::envChanged($base);
        $reset = AppVersion::adminResetRequested($base);
        $keyMissing = blank(config('app.key')) || AppVersion::envKeyMissing($base);
        $demo = AppVersion::demoRequested($base);
        if ($version === null || (! $pending && ! $envChanged && ! $reset && ! $keyMissing && ! $demo)) {
            $this->info('Up to date'.($version ? " ({$version})" : '').'.');

            return self::SUCCESS;
        }

        // Two cron runs can overlap while a slow update is still going: only one does the work.
        $lock = fopen($base.'/storage/framework/auto-update.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return self::SUCCESS;
        }

        try {
            if ($keyMissing) {
                $this->ensureKey($base);
            }
            if ($pending) {
                if (! $this->update($base, $version)) {
                    return self::FAILURE;
                }
            } elseif ($envChanged || $reset || $keyMissing) {
                $this->log(match (true) {
                    $reset => 'Admin password reset requested (storage/app/reset-admin-password).',
                    $envChanged => '.env was saved: refreshing the settings.',
                    default => 'Refreshing the settings.',
                });
                $this->refreshSettings();
            }

            $this->ensureAdmin();
            AppVersion::markEnvApplied($base);
            if ($demo) {
                $this->loadDemo($base);
            }

            return self::SUCCESS;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** A new package: APP_KEY if empty, then lotlink:deploy (with --seed on a first install), then record the version. */
    private function update(string $base, string $version): bool
    {
        $this->log("Updating to {$version} (was ".(AppVersion::deployed($base) ?? 'not installed').')');

        try {
            // Also when the tables exist but were never seeded (an earlier install stopped half-way): seeding is idempotent.
            $firstInstall = ! Schema::hasTable('migrations') || ! Schema::hasTable('plans') || ! Plan::query()->exists();
        } catch (Throwable $e) {
            $this->log('Cannot reach the database: '.$e->getMessage().' Check DB_HOST, DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env (put passwords in double quotes).');

            return false;
        }
        if ($firstInstall) {
            $this->log('First install: creating the tables, plans, car catalogue and the admin from ADMIN_EMAIL / ADMIN_PASSWORD.');
        }

        if ($this->runDeploy($firstInstall) !== self::SUCCESS) {
            $this->log('The update failed (see above). It will be tried again in a minute; the site shows "updating" until it works.');

            return false;
        }

        AppVersion::markDeployed($base, $version);
        $this->log("Done: running {$version}.");

        return true;
    }

    /**
     * APP_KEY must be in .env (the settings cache is rebuilt from it). If .env lost it but the site still runs with one
     * (from the cached settings), that key is written back, so encrypted data stays readable; otherwise a new one is made.
     */
    private function ensureKey(string $base): void
    {
        $key = (string) config('app.key');
        if ($key !== '' && AppVersion::envKey($base) === $key) {
            return;
        }
        if ($key === '') {
            $key = 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher', 'AES-256-CBC')));
            $message = 'APP_KEY is empty: created one in .env.';
        } else {
            $message = 'APP_KEY was missing from .env: put back the key the site was using.';
        }
        if (! AppVersion::writeEnvKey($base, $key)) {
            $this->log('APP_KEY is missing and .env could not be written: check that caryard/.env exists and is writable (644).');

            return;
        }
        config(['app.key' => $key]);
        $this->log($message);
    }

    /** Re-cache the settings from the saved .env, and use them in this run too (the scheduler carries on after this). */
    private function refreshSettings(): void
    {
        $started = time();
        if ($this->runArtisan('config:cache', []) === self::SUCCESS) {
            $cached = $this->laravel->getCachedConfigPath();
            if (is_file($cached) && filemtime($cached) >= $started) {
                $this->laravel['config']->set(require $cached);
            }
        }
    }

    /** Demo data, once per request file (removed first, so a failure isn't retried every minute). */
    private function loadDemo(string $base): void
    {
        @unlink($base.'/storage/app/load-demo-data');
        $this->log('Loading demo data (storage/app/load-demo-data)…');
        try {
            foreach ($this->runDemo() as $line) {
                $this->log($line);
            }
        } catch (Throwable $e) {
            $this->log('Demo data could not be loaded: '.$e->getMessage().' Create the file again to retry.');
        }
    }

    /** @return list<string> */
    protected function runDemo(): array
    {
        return app(LoadDemoData::class)->run();
    }

    /** Create the first admin, or fix its password from .env, and say so in the log. */
    private function ensureAdmin(): void
    {
        try {
            $result = app(EnsureFirstAdmin::class)->run(fromEnvChange: true);
            if ($result['status'] !== 'kept') {
                $this->log($result['message']);
            }
        } catch (Throwable $e) {
            $this->log('Could not check the admin account: '.$e->getMessage());
        }
    }

    /** The app folder; its own method so tests can use a temporary one. */
    protected function base(): string
    {
        return base_path();
    }

    /** lotlink:deploy; its own method so tests can replace it. */
    protected function runDeploy(bool $seed): int
    {
        return $this->runArtisan('lotlink:deploy', $seed ? ['--seed' => true] : []);
    }

    /** @param  array<string, mixed>  $arguments */
    protected function runArtisan(string $command, array $arguments): int
    {
        $output = new BufferedOutput;
        try {
            $code = Artisan::call($command, $arguments, $output);
        } catch (Throwable $e) {
            $output->writeln($e->getMessage());
            $code = self::FAILURE;
        }
        $this->log("$ php artisan {$command}\n".trim($output->fetch()));

        return $code;
    }

    private function log(string $message): void
    {
        $this->line($message);
        @file_put_contents($this->base().'/storage/logs/auto-update.log', '['.now()->toDateTimeString().'] '.$message.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
