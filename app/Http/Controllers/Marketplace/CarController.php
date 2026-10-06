<?php

namespace App\Http\Controllers\Marketplace;

use App\Domain\Analytics\Support\Tracker;
use App\Domain\Finance\Support\FinanceCalculator;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Seo\StructuredData;
use App\Domain\Sharing\Models\ShareLink;
use App\Domain\Sharing\Support\ShareCard;
use App\Domain\Support\Input;
use App\Http\Controllers\Account\BudgetController;
use App\Http\Controllers\Controller;
use App\Http\Presenters\DealsPresenter;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CarController extends Controller
{
    /** /car/{ulid}-{slug}: the ULID finds the car; the slug is for people and search engines. */
    public function __invoke(Request $request, string $ref, ShareCard $cards): Response|RedirectResponse
    {
        $ulid = strtolower(substr($ref, 0, 26));

        $vehicle = Vehicle::query()
            ->withoutGlobalScope('lot')
            ->where('ulid', $ulid)
            ->with(['make', 'model', 'lot', 'cover', 'features', 'inspection', 'media' => fn ($q) => $q->where('status', 'ready')])
            ->first();

        abort_if($vehicle === null, 404);

        $lotLive = $vehicle->lot->status === LotStatus::Active;
        // Held cars (reported, or hidden by an admin) are off CarYard until the review is done.
        $public = $lotLive && in_array($vehicle->status, [...VehicleStatus::live(), VehicleStatus::Sold], true) && ! $vehicle->isHeld();
        // Lot staff can preview drafts and cars at a seller that isn't approved yet.
        $preview = ! $public && $request->user()?->hasLotRole($vehicle->lot);

        abort_unless($public || $preview, 404);

        if ($ref !== substr($vehicle->publicPath(), 5)) {
            return redirect($vehicle->publicPath(), 301);
        }

        $user = $request->user();

        // TDD M15: count the view (not the seller's own staff), attributed to a share link if it came from one.
        if ($public && ! $user?->hasLotRole($vehicle->lot)) {
            $channel = $request->filled('ref') ? ShareLink::where('code', Input::query($request, 'ref'))->first()?->platform->value : null;
            Tracker::view($request, $vehicle, $channel);
        }

        $cover = $vehicle->media->first();
        // Link previews show the branded share card once it has been rendered.
        $card = $vehicle->share_card_hash !== null && $vehicle->share_card_hash === $cards->hash($vehicle) ? $cards->urls($vehicle) : null;
        $price = $vehicle->price !== null ? intdiv($vehicle->price, 100) : null;

        return Inertia::render('Marketplace/Car', [
            'car' => MarketplacePresenter::vehicle($vehicle),
            'lot' => MarketplacePresenter::lot($vehicle->lot),
            'sold' => $vehicle->status === VehicleStatus::Sold,
            'preview' => $preview ? ($vehicle->status === VehicleStatus::Draft ? 'This is a draft only your team can see.' : 'Only your team can see this until the car and seller are live.') : null,
            'saved' => $user ? $user->favourites()->whereKey($vehicle->id)->exists() : false,
            'similar' => $this->similar($vehicle),
            'deals' => $public ? DealsPresenter::forCar($vehicle, $user) : null,
            'inspector' => $public && ($user?->isInspector() ?? false),
            'finance' => $price ? [
                'price' => $price,
                'from' => FinanceCalculator::fromPrice($price),
                'ownership' => FinanceCalculator::ownership($price, $vehicle->engine_cc, $vehicle->year),
                'defaults' => BudgetController::finance(),
            ] : null,
        ])->withViewData(['meta' => [
            'title' => $vehicle->title().($vehicle->price ? ' — '.$vehicle->formattedPrice() : ''),
            'description' => implode(' · ', array_filter([
                $vehicle->mileage_km !== null ? number_format($vehicle->mileage_km).' km' : null,
                $vehicle->transmission?->label(),
                $vehicle->condition?->label(),
                "at {$vehicle->lot->name}".($vehicle->lot->city ? ", {$vehicle->lot->city}" : ''),
            ])),
            'image' => $card['square'] ?? $cover?->urls()[1600] ?? null,
            'url' => url($vehicle->publicPath()),
            'type' => 'product',
            'jsonld' => $public ? StructuredData::car($vehicle) : null,
            'robots' => $public && $vehicle->status !== VehicleStatus::Sold ? null : 'noindex',
        ]]);
    }

    /** Quick look from a listing card: the car's photos without opening the page. */
    public function photos(string $car): JsonResponse
    {
        $vehicle = Vehicle::query()
            ->marketplace()
            ->where('vehicles.ulid', strtolower($car))
            ->with(['make', 'model', 'media' => fn ($q) => $q->where('status', 'ready')])
            ->firstOrFail();

        return response()->json([
            'title' => $vehicle->title(),
            'price' => $vehicle->formattedPrice(),
            'url' => $vehicle->publicPath(),
            'photos' => $vehicle->media->map(fn ($m) => MarketplacePresenter::image($m))->filter()->values(),
        ])->header('Cache-Control', 'public, max-age=300');
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
