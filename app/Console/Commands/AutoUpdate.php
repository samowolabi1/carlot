<?php

namespace App\Console\Commands;

use App\Domain\System\AppVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Finishes an uploaded cPanel package without a terminal (see AppVersion). The cron job's schedule:run calls it first
 * whenever VERSION differs from the last deployed one: creates APP_KEY if it's empty, runs lotlink:deploy (with --seed
 * on a first install), then records the version. Everything is written to storage/logs/auto-update.log, which can be
 * read in cPanel's File Manager. A failed update is retried on the next minute; the site shows "updating" meanwhile.
 */
class AutoUpdate extends Command
{
    protected $signature = 'lotlink:auto-update';

    protected $description = 'Finish an uploaded package: migrate (install the first time), cache and record the version';

    public function handle(): int
    {
        $base = $this->base();
        $version = AppVersion::current($base);
        if ($version === null || ! AppVersion::pending($base)) {
            $this->info('Up to date'.($version ? " ({$version})" : '').'.');

            return self::SUCCESS;
        }

        // Two cron runs can overlap while a slow update is still going: only one does the work.
        $lock = fopen($base.'/storage/framework/auto-update.lock', 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return self::SUCCESS;
        }

        try {
            $this->log("Updating to {$version} (was ".(AppVersion::deployed($base) ?? 'not installed').')');

            if (blank(config('app.key'))) {
                $this->log('APP_KEY is empty: creating one.');
                $this->runArtisan('key:generate', ['--force' => true]);
            }

            try {
                $firstInstall = ! Schema::hasTable('migrations');
            } catch (Throwable $e) {
                $this->log('Cannot reach the database: '.$e->getMessage().' Check DB_HOST, DB_DATABASE, DB_USERNAME and DB_PASSWORD in .env.');

                return self::FAILURE;
            }
            if ($firstInstall) {
                $this->log('First install: creating the tables, plans, car catalogue and the admin from ADMIN_EMAIL / ADMIN_PASSWORD.');
            }

            $code = $this->runDeploy($firstInstall);
            if ($code !== self::SUCCESS) {
                $this->log('The update failed (see above). It will be tried again in a minute; the site shows "updating" until it works.');

                return self::FAILURE;
            }

            AppVersion::markDeployed($base, $version);
            $this->log("Done: running {$version}.");

            return self::SUCCESS;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
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
