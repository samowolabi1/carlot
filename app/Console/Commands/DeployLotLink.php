<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Everything to run after uploading a new version (cPanel Terminal, or a one-off cron job if there's no terminal):
 * database changes, the image repository folder, caches, and a health check at the end.
 */
class DeployLotLink extends Command
{
    protected $signature = 'lotlink:deploy {--seed : also seed plans, the catalogue and the admin (first install)} {--no-cache : skip config/route/view caches}';

    protected $description = 'Migrate, prepare the image repository, cache config/routes/views and check the server';

    public function handle(): int
    {
        $this->call('down', ['--retry' => 15]);

        try {
            $this->call('migrate', ['--force' => true]);
            if ($this->option('seed')) {
                $this->call('db:seed', ['--force' => true]);
            }

            // The image repository (public/media, or MEDIA_ROOT) with its "no scripts" guard.
            $root = (string) config('filesystems.disks.media.root');
            File::ensureDirectoryExists($root, 0755);
            if (! is_file($root.'/.htaccess') && is_file(public_path('media/.htaccess')) && realpath($root) !== realpath(public_path('media'))) {
                File::copy(public_path('media/.htaccess'), $root.'/.htaccess');
            }
            if (is_dir(storage_path('app/public')) && config('lotlink.media_disk') === 'media') {
                $this->call('media:move-to-public');
            }

            $this->call('optimize:clear');
            if (! $this->option('no-cache')) {
                $this->call('config:cache');
                $this->call('route:cache');
                $this->call('view:cache');
                $this->call('event:cache');
            }
            $this->call('filament:optimize');
            $this->call('queue:restart');
            $this->call('sitemap:generate');
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            $this->call('up');

            return self::FAILURE;
        }

        $this->call('up');
        $this->call('lotlink:doctor'); // shown for information; a fresh install's cron simply hasn't run yet

        return self::SUCCESS;
    }
}
