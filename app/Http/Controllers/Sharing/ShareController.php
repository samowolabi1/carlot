<?php

namespace App\Http\Controllers\Sharing;

use App\Domain\Analytics\Support\Tracker;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Domain\Sharing\Actions\CreateShareLink;
use App\Domain\Sharing\Enums\SharePlatform;
use App\Domain\Sharing\Jobs\RenderShareCard;
use App\Domain\Sharing\Models\ShareLink;
use App\Domain\Sharing\Support\ShareCard;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Share links (TDD M9): POST /shares makes a tracked /c/{code} link; GET /c/{code} follows it. */
class ShareController extends Controller
{
    public function store(Request $request, CreateShareLink $create, ShareCard $cards): JsonResponse
    {
        $data = $request->validate([
            'vehicle' => ['required_without:lot', 'nullable', 'string', 'size:26'],
            'lot' => ['required_without:vehicle', 'nullable', 'string', 'max:120'],
            'platform' => ['required', Rule::enum(SharePlatform::class)->except([SharePlatform::Qr])],
        ]);

        $target = filled($data['vehicle'] ?? null)
            ? Vehicle::query()->marketplace()->with(['make', 'model', 'lot', 'cover'])->where('vehicles.ulid', strtolower($data['vehicle']))->first()
            : Lot::query()->where('slug', $data['lot'])->where('status', LotStatus::Active)->first();

        abort_if($target === null, 404);

        $platform = SharePlatform::from($data['platform']);
        $user = $request->user();

        // Guests reuse their link for the session instead of making a new row per tap.
        $key = 'shares.'.($target instanceof Vehicle ? 'v'.$target->id : 'l'.$target->id).".{$platform->value}";
        $link = $user === null && ($code = $request->session()->get($key))
            ? ShareLink::where('code', $code)->first()
            : null;
        if ($link === null) {
            $link = $create->run($target, $platform, $user);
            Tracker::record('share', $target instanceof Vehicle ? $target->lot_id : $target->id, $target instanceof Vehicle ? $target->id : null, $platform->value);
        }

        if ($user === null) {
            $request->session()->put($key, $link->code);
        }

        $card = null;
        if ($target instanceof Vehicle) {
            if ($target->share_card_hash !== $cards->hash($target)) {
                RenderShareCard::refresh($target->id);
                $target->refresh();
            }
            $card = $target->share_card_hash === $cards->hash($target) ? $cards->urls($target) : null;
        }

        return response()->json([
            'code' => $link->code,
            'url' => $link->url(),
            'cards' => $card,
        ]);
    }

    public function go(Request $request, string $code): RedirectResponse
    {
        $link = ShareLink::with(['vehicle' => fn ($q) => $q->withTrashed(), 'lot'])->where('code', $code)->first();

        abort_if($link === null, 404);

        $lotLive = $link->lot?->status === LotStatus::Active;
        $vehicle = $link->vehicle;
        $carLive = $vehicle !== null && $lotLive && ! $vehicle->trashed()
            && in_array($vehicle->status, [...VehicleStatus::live(), VehicleStatus::Sold], true);

        $target = match (true) {
            $carLive => $vehicle->publicPath(),
            $lotLive => route('lots.show', $link->lot, false),
            default => null,
        };

        if ($target === null) {
            return redirect()->route('cars.index');
        }

        $link->registerClick($request->userAgent());

        return redirect($target.'?'.http_build_query(['ref' => $link->code]));
    }
}
