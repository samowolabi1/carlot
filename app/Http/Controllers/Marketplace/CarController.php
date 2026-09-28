<?php

namespace App\Http\Controllers\Marketplace;

use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Enums\LotStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CarController extends Controller
{
    /** /car/{ulid}-{slug}: the ULID finds the car; the slug is for people and search engines. */
    public function __invoke(Request $request, string $ref): Response|RedirectResponse
    {
        $ulid = strtolower(substr($ref, 0, 26));

        $vehicle = Vehicle::query()
            ->withoutGlobalScope('lot')
            ->where('ulid', $ulid)
            ->with(['make', 'model', 'lot', 'features', 'media' => fn ($q) => $q->where('status', 'ready')])
            ->first();

        abort_if($vehicle === null, 404);

        $lotLive = $vehicle->lot->status === LotStatus::Active;
        $public = $lotLive && in_array($vehicle->status, [...VehicleStatus::live(), VehicleStatus::Sold], true);
        // Lot staff can preview drafts and cars at a lot that isn't approved yet.
        $preview = ! $public && $request->user()?->hasLotRole($vehicle->lot);

        abort_unless($public || $preview, 404);

        if ($ref !== substr($vehicle->publicPath(), 5)) {
            return redirect($vehicle->publicPath(), 301);
        }

        $user = $request->user();
        $cover = $vehicle->media->first();

        return Inertia::render('Marketplace/Car', [
            'car' => MarketplacePresenter::vehicle($vehicle),
            'lot' => MarketplacePresenter::lot($vehicle->lot),
            'sold' => $vehicle->status === VehicleStatus::Sold,
            'preview' => $preview ? ($vehicle->status === VehicleStatus::Draft ? 'This is a draft only your team can see.' : 'Only your team can see this until the car and lot are live.') : null,
            'saved' => $user ? $user->favourites()->whereKey($vehicle->id)->exists() : false,
            'similar' => $this->similar($vehicle),
        ])->withViewData(['meta' => [
            'title' => $vehicle->title().($vehicle->price ? ' — '.$vehicle->formattedPrice() : ''),
            'description' => implode(' · ', array_filter([
                $vehicle->mileage_km !== null ? number_format($vehicle->mileage_km).' km' : null,
                $vehicle->transmission?->label(),
                $vehicle->condition?->label(),
                "at {$vehicle->lot->name}".($vehicle->lot->city ? ", {$vehicle->lot->city}" : ''),
            ])),
            'image' => $cover?->urls()[1600] ?? null,
            'url' => url($vehicle->publicPath()),
            'type' => 'product',
            'robots' => $public && $vehicle->status !== VehicleStatus::Sold ? null : 'noindex',
        ]]);
    }

    private function similar(Vehicle $vehicle): array
    {
        return Vehicle::query()
            ->marketplace()
            ->whereKeyNot($vehicle->id)
            ->where(fn ($q) => $q->where('vehicle_model_id', $vehicle->vehicle_model_id)
                ->orWhere(fn ($q) => $q->where('body_type', $vehicle->body_type)->whereBetween('price', [(int) ($vehicle->price * 0.7), (int) ($vehicle->price * 1.3)])))
            ->with(['make', 'model', 'lot', 'cover'])
            ->latest('listed_at')
            ->limit(4)
            ->get()
            ->map(fn (Vehicle $v) => MarketplacePresenter::card($v))
            ->all();
    }
}
