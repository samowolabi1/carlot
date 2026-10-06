<?php

namespace App\Domain\Social\Jobs;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Models\VehicleMedia;
use App\Domain\Sharing\Actions\CreateShareLink;
use App\Domain\Sharing\Enums\SharePlatform;
use App\Domain\Social\Gateways\SocialPublisher;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Social\Models\SocialPost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * PublishToSocial (TDD M9): a newly published car goes to each connected account with
 * auto-post on, once. The link in the caption is a tracked share link for that platform.
 */
class PublishToSocial implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Something about a record that has since been deleted is dropped, not retried into failed_jobs. */
    public bool $deleteWhenMissingModels = true;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $vehicleId, public readonly ?int $accountId = null) {}

    public function handle(SocialPublisher $publisher, CreateShareLink $links): void
    {
        $vehicle = Vehicle::withoutGlobalScopes()->with(['make', 'model', 'lot', 'cover'])->find($this->vehicleId);

        if ($vehicle === null || ! $vehicle->isOnMarketplace()) {
            return;
        }

        $image = $vehicle->cover ? self::jpeg($vehicle->cover) : null;
        if ($image === null) {
            return;
        }

        $accounts = SocialAccount::withoutGlobalScopes()->where('lot_id', $vehicle->lot_id)
            ->when($this->accountId, fn ($q) => $q->whereKey($this->accountId), fn ($q) => $q->where('auto_post', true))->get();

        foreach ($accounts as $account) {
            $post = SocialPost::withoutGlobalScopes()->firstOrCreate(
                ['vehicle_id' => $vehicle->id, 'social_account_id' => $account->id],
                ['lot_id' => $vehicle->lot_id, 'status' => 'queued'],
            );

            if ($post->status === 'posted') {
                continue;
            }

            $platform = $account->provider === 'instagram' ? SharePlatform::Instagram : SharePlatform::Facebook;
            $caption = self::caption($vehicle, $links->run($vehicle, $platform)->url());

            try {
                $id = $publisher->publish($account, $image, $caption);
                $post->forceFill(['status' => 'posted', 'external_id' => $id, 'error' => null, 'posted_at' => now(), 'attempts' => $post->attempts + 1])->save();
                $account->forceFill(['last_error' => null, 'last_error_at' => null])->save();
            } catch (Throwable $e) {
                $reason = mb_substr($e->getMessage(), 0, 250);
                $post->forceFill(['status' => 'failed', 'error' => $reason, 'attempts' => $post->attempts + 1])->save();
                $account->forceFill(['last_error' => $reason, 'last_error_at' => now()])->save();
            }
        }
    }

    /** Instagram only takes JPEGs, so the cover gets a JPEG copy on the media disk. */
    public static function jpeg(VehicleMedia $cover): ?string
    {
        $disk = Storage::disk(config('lotlink.media_disk'));
        $path = 'social/'.$cover->ulid.'.jpg';

        if (! $disk->exists($path)) {
            if ($cover->path === null || ! $disk->exists($cover->path)) {
                return null;
            }
            $image = (new ImageManager(new Driver))->read((string) $disk->get($cover->path))->scaleDown(width: 1440);
            $disk->put($path, (string) $image->toJpeg(quality: 85), ['visibility' => 'public', 'ContentType' => 'image/jpeg']);
        }

        return $disk->url($path);
    }

    public static function caption(Vehicle $v, string $link): string
    {
        $specs = implode(' · ', array_filter([
            $v->mileage_km !== null ? number_format($v->mileage_km).' km' : null,
            $v->transmission?->label(),
            $v->condition?->label(),
        ]));

        return implode("\n", array_filter([
            trim($v->title().($v->price ? ' — '.$v->formattedPrice() : '')),
            $specs ?: null,
            $v->lot->name.($v->lot->city ? ', '.$v->lot->city : ''),
            '',
            'See all the photos and book a test drive: '.$link,
        ], fn ($line) => $line !== null));
    }
}
