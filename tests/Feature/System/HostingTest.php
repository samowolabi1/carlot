<?php

use App\Domain\Accounts\Models\User;
use App\Domain\System\ServerHealth;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

it('keeps public images in public/media, served as plain files, with scripts blocked', function () {
    config(['lotlink.media_disk' => 'media']);

    expect(config('filesystems.disks.media.root'))->toBe(public_path('media'))
        ->and(Storage::disk('media')->url('vehicles/a.webp'))->toBe(rtrim(config('app.url'), '/').'/media/vehicles/a.webp')
        ->and(file_get_contents(public_path('media/.htaccess')))->toContain('php_flag engine off')->toContain('Options -Indexes');
});

it('copies images saved in storage/app/public into the image repository, once', function () {
    Storage::fake('public');
    Storage::fake('media');
    Storage::disk('public')->put('vehicles/v1/a-800.webp', 'webp');
    Storage::disk('public')->put('lots/l1/logo.png', 'png');

    $this->artisan('media:move-to-public')->expectsOutputToContain('Copied 2 files')->assertSuccessful();
    $this->artisan('media:move-to-public')->expectsOutputToContain('Copied 0 files, 2 already there')->assertSuccessful();

    Storage::disk('media')->assertExists(['vehicles/v1/a-800.webp', 'lots/l1/logo.png']);
});

it('records a cron heartbeat every minute', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e instanceof CallbackEvent && $e->description === 'cron-heartbeat');
    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *');

    Cache::forget('lotlink:cron-heartbeat');
    $event->run(app());
    expect(Cache::get('lotlink:cron-heartbeat'))->not->toBeNull();
});

it('checks the server and says what to fix', function () {
    Cache::forget('lotlink:cron-heartbeat');
    $cron = collect(ServerHealth::checks())->firstWhere('label', 'Cron job running');
    expect($cron['status'])->toBe(ServerHealth::FAIL)->and($cron['detail'])->toContain('schedule:run');

    Cache::forever('lotlink:cron-heartbeat', now()->toIso8601String());
    expect(collect(ServerHealth::checks())->firstWhere('label', 'Cron job running')['status'])->toBe(ServerHealth::OK);

    config(['lotlink.media_disk' => 'public']);
    expect(collect(ServerHealth::checks())->firstWhere('label', 'Image repository')['status'])->toBe(ServerHealth::WARN);

    config(['queue.default' => 'database', 'lotlink.queue_via_cron' => false]);
    expect(collect(ServerHealth::checks())->firstWhere('label', 'Queue')['detail'])->toContain('QUEUE_VIA_CRON');

    $this->artisan('lotlink:doctor')->expectsOutputToContain('Cron and queue')->expectsOutputToContain('Image repository');
});

it('shows System health to admins only', function () {
    $this->actingAs(User::factory()->create())->get('/admin/system-health')->assertForbidden();
    $this->actingAs(User::factory()->admin()->create())->get('/admin/system-health')->assertOk()->assertSee('Cron job running')->assertSee('GD can write WebP');
});

it('works the queue from the cron job on shared hosting', function () {
    $queueWork = fn () => collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'queue:work'));
    expect($queueWork())->toBeNull(); // off unless QUEUE_VIA_CRON=true

    config(['lotlink.queue_via_cron' => true]);
    require base_path('routes/console.php');

    $event = $queueWork();
    expect($event)->not->toBeNull()->and($event->expression)->toBe('* * * * *')
        ->and($event->command)->toContain('--stop-when-empty')->toContain('--queue=critical,media,notifications,default')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('trusts the configured proxy, so HTTPS behind Cloudflare is detected even with cached config', function () {
    Route::get('/_proxy-probe', fn (Request $r) => $r->isSecure() ? 'https' : 'http');

    $this->get('/_proxy-probe', ['X-Forwarded-Proto' => 'https'])->assertSeeText('http')->assertDontSeeText('https');

    config(['trustedproxy.proxies' => '*']);
    $this->get('/_proxy-probe', ['X-Forwarded-Proto' => 'https'])->assertSeeText('https');
});
