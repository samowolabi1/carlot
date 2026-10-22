<?php

use App\Console\Commands\AutoUpdate;
use App\Domain\Accounts\Models\User;
use App\Domain\System\AppVersion;
use App\Domain\System\ScheduledTask;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/* cPanel without a terminal: an uploaded package (VERSION) is finished by the cron job (AppVersion + lotlink:auto-update). */

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/caryard-update-'.uniqid();
    foreach (['storage/app', 'storage/logs', 'storage/framework', 'bootstrap/cache'] as $dir) {
        File::ensureDirectoryExists($this->base.'/'.$dir);
    }
});

afterEach(fn () => File::deleteDirectory($this->base));

/** Runs lotlink:auto-update against the temporary folder, recording the artisan commands instead of running them. */
function autoUpdate(string $base, int $deployResult = 0): object
{
    $command = new class($base, $deployResult) extends AutoUpdate
    {
        /** @var list<array{string, array<string, mixed>}> */
        public array $calls = [];

        public function __construct(private string $folder, private int $deployResult)
        {
            parent::__construct();
        }

        protected function base(): string
        {
            return $this->folder;
        }

        protected function runArtisan(string $command, array $arguments): int
        {
            $this->calls[] = [$command, $arguments];

            return $command === 'lotlink:deploy' ? $this->deployResult : 0;
        }
    };
    $command->setLaravel(app());
    $command->run(new ArrayInput([]), new BufferedOutput);

    return $command;
}

it('does nothing without a version stamp (local and CI)', function () {
    expect(AppVersion::pending($this->base))->toBeFalse()
        ->and(autoUpdate($this->base)->calls)->toBe([]);
});

it('finishes an uploaded package once and records its version', function () {
    file_put_contents($this->base.'/VERSION', "abc123-202610220900\n");
    expect(AppVersion::pending($this->base))->toBeTrue();

    expect(autoUpdate($this->base)->calls)->toBe([['lotlink:deploy', []]])
        ->and(AppVersion::deployed($this->base))->toBe('abc123-202610220900')
        ->and(AppVersion::pending($this->base))->toBeFalse()
        ->and(file_get_contents($this->base.'/storage/logs/auto-update.log'))->toContain('Done: running abc123-202610220900');

    // The next cron run has nothing to do; a newer upload is picked up again.
    expect(autoUpdate($this->base)->calls)->toBe([]);
    file_put_contents($this->base.'/VERSION', "def456-202611010900\n");
    expect(autoUpdate($this->base)->calls)->toBe([['lotlink:deploy', []]]);
});

it('keeps the site on "updating" and retries when the update fails', function () {
    file_put_contents($this->base.'/VERSION', 'abc123');
    AppVersion::markDeployed($this->base, 'old999');

    autoUpdate($this->base, deployResult: 1);

    expect(AppVersion::deployed($this->base))->toBe('old999')
        ->and(AppVersion::pending($this->base))->toBeTrue()
        ->and(file_get_contents($this->base.'/storage/logs/auto-update.log'))->toContain('tried again in a minute');
});

it('installs the first time: writes an app key into .env and seeds', function () {
    file_put_contents($this->base.'/VERSION', 'abc123');
    file_put_contents($this->base.'/.env', "APP_NAME=CarYard\nAPP_KEY=\"\"\nDB_CONNECTION=mysql\n");
    config(['app.key' => '']);
    Schema::drop('migrations');

    expect(autoUpdate($this->base)->calls)->toBe([['lotlink:deploy', ['--seed' => true]]])
        ->and(AppVersion::deployed($this->base))->toBe('abc123')
        ->and(AppVersion::envKey($this->base))->toStartWith('base64:')->toBe(config('app.key'))
        ->and(file_get_contents($this->base.'/.env'))->toContain("APP_NAME=CarYard\nAPP_KEY=base64:")->toContain("\nDB_CONNECTION=mysql\n")->not->toContain('""');
});

it('puts back the key the site was using when .env loses it', function () {
    file_put_contents($this->base.'/VERSION', 'abc123');
    AppVersion::markDeployed($this->base, 'abc123');
    file_put_contents($this->base.'/.env', "APP_NAME=CarYard\nAPP_KEY=\n");
    AppVersion::markEnvApplied($this->base);
    $running = config('app.key');
    expect(AppVersion::needsAttention($this->base))->toBeTrue();

    expect(autoUpdate($this->base)->calls)->toBe([['config:cache', []]])
        ->and(AppVersion::envKey($this->base))->toBe($running)
        ->and(file_get_contents($this->base.'/storage/logs/auto-update.log'))->toContain('put back the key');
    expect(AppVersion::needsAttention($this->base))->toBeFalse();
});

it('runs scheduled commands inside the cron job where proc_open is disabled', function () {
    config(['lotlink.schedule_in_process' => true]);
    expect(ScheduledTask::command('otp:prune'))->toBeInstanceOf(CallbackEvent::class)
        ->description->toBe('otp:prune');

    config(['lotlink.schedule_in_process' => false]);
    expect(ScheduledTask::command('otp:prune'))->not->toBeInstanceOf(CallbackEvent::class);
});

it('drops the old release caches once per upload, before the app boots', function () {
    file_put_contents($this->base.'/bootstrap/cache/config.php', '<?php return [];');
    file_put_contents($this->base.'/bootstrap/cache/routes-v7.php', '<?php');

    AppVersion::clearStaleCaches($this->base); // no VERSION: nothing touched
    expect(is_file($this->base.'/bootstrap/cache/config.php'))->toBeTrue();

    file_put_contents($this->base.'/VERSION', 'abc123');
    AppVersion::clearStaleCaches($this->base);
    expect(is_file($this->base.'/bootstrap/cache/config.php'))->toBeFalse()
        ->and(is_file($this->base.'/bootstrap/cache/routes-v7.php'))->toBeFalse();

    // The new release's caches (written by lotlink:deploy) are kept from then on.
    file_put_contents($this->base.'/bootstrap/cache/config.php', '<?php return [];');
    AppVersion::clearStaleCaches($this->base);
    expect(is_file($this->base.'/bootstrap/cache/config.php'))->toBeTrue();
});

it('shows visitors a short updating page while an update waits', function () {
    expect(AppVersion::updatingPage())->toContain('is updating')->toContain('try again in a minute');
});

it('applies a saved .env: re-caches the settings and creates the admin, once per save', function () {
    file_put_contents($this->base.'/VERSION', 'abc123');
    AppVersion::markDeployed($this->base, 'abc123');
    file_put_contents($this->base.'/.env', "ADMIN_EMAIL=boss@caryardng.com\n");
    config(['lotlink.first_admin' => ['email' => 'boss@caryardng.com', 'password' => 'a-long-first-password', 'phone' => null]]);
    expect(AppVersion::needsAttention($this->base))->toBeTrue();

    expect(autoUpdate($this->base)->calls)->toBe([['config:cache', []]])
        ->and(User::where('email', 'boss@caryardng.com')->exists())->toBeTrue()
        ->and(file_get_contents($this->base.'/storage/logs/auto-update.log'))->toContain('.env was saved')->toContain('Admin account created');

    expect(AppVersion::needsAttention($this->base))->toBeFalse()
        ->and(autoUpdate($this->base)->calls)->toBe([]);

    file_put_contents($this->base.'/.env', "ADMIN_EMAIL=boss@caryardng.com\nMAIL_HOST=mail.caryardng.com\n");
    expect(autoUpdate($this->base)->calls)->toBe([['config:cache', []]]);
    @unlink(storage_path('app/first-admin-password'));
});

it('passes list-style options to in-process commands as one command line', function () {
    expect(ScheduledTask::commandLine('queue:work', ['--queue=default,media', '--stop-when-empty', '--max-time=50']))
        ->toBe('queue:work --queue=default,media --stop-when-empty --max-time=50')
        ->and(ScheduledTask::commandLine('sanctum:prune-expired --hours=24', []))->toBe('sanctum:prune-expired --hours=24')
        ->and(ScheduledTask::commandLine('demo', ['--name' => 'two words', '--force' => true, '--skip' => false]))->toBe('demo --name="two words" --force');
});
