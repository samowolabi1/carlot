<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/** Makes the VAPID key pair for web push and writes it to .env (once: changing it breaks every device's push). */
class GenerateVapidKeys extends Command
{
    protected $signature = 'push:vapid {--show : Print the keys instead of writing .env} {--force : Replace keys that are already set}';

    protected $description = 'Generate the VAPID keys web push notifications need';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        if ($this->option('show')) {
            $this->line("VAPID_PUBLIC_KEY={$keys['publicKey']}");
            $this->line("VAPID_PRIVATE_KEY={$keys['privateKey']}");

            return self::SUCCESS;
        }

        if (filled(config('services.webpush.public_key')) && ! $this->option('force')) {
            $this->warn('VAPID keys are already set. Replacing them turns push off on every device; use --force if you really mean it.');

            return self::FAILURE;
        }

        $path = $this->laravel->environmentFilePath();
        $env = is_file($path) ? (string) file_get_contents($path) : '';
        foreach (['VAPID_PUBLIC_KEY' => $keys['publicKey'], 'VAPID_PRIVATE_KEY' => $keys['privateKey']] as $name => $value) {
            $env = preg_match("/^{$name}=.*$/m", $env)
                ? (string) preg_replace("/^{$name}=.*$/m", "{$name}={$value}", $env)
                : rtrim($env, "\n")."\n{$name}={$value}\n";
        }
        file_put_contents($path, $env);

        $this->info('VAPID keys written to .env. Run `php artisan config:clear` (or config:cache in production).');

        return self::SUCCESS;
    }
}
