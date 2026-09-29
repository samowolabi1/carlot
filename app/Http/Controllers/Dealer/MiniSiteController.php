<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Domain\Sharing\Support\Printables;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** "Mini-site and QR codes" (design D-MiniSite, TDD M6): the lot's link, a gate poster and windscreen stickers. */
class MiniSiteController extends Controller
{
    public function show(Lot $lot): Response
    {
        Gate::authorize('view', $lot);

        return Inertia::render('Dealer/MiniSite', [
            'site' => [
                'url' => route('lots.show', $lot),
                'live' => $lot->status === LotStatus::Active,
                'tagline' => $lot->tagline,
                'brand_color' => $lot->brand_color ?? '#16302B',
                'logo_url' => $lot->logo_url,
                'initials' => $lot->initials(),
            ],
            'stickers' => $this->stickerCars()->count(),
            'canEdit' => Gate::allows('update', $lot),
        ]);
    }

    public function poster(Request $request, Lot $lot, Printables $printables): HttpResponse
    {
        Gate::authorize('view', $lot);
        $size = in_array($request->query('size'), Printables::SIZES, true) ? (string) $request->query('size') : 'a4';

        return $this->pdf($printables->poster($lot, $size), "{$lot->slug}-poster-{$size}.pdf");
    }

    public function stickers(Lot $lot, Printables $printables): HttpResponse
    {
        Gate::authorize('view', $lot);
        $cars = $this->stickerCars()->with(['make', 'model'])->orderBy('listed_at')->limit(120)->get();
        abort_if($cars->isEmpty(), 404, 'No cars to print stickers for yet.');

        return $this->pdf($printables->stickers($lot, $cars), "{$lot->slug}-windscreen-stickers.pdf");
    }

    /** @return Builder<Vehicle> cars on the lot now: available or reserved */
    private function stickerCars()
    {
        return Vehicle::query()->whereIn('status', [VehicleStatus::Available, VehicleStatus::Reserved])->whereNull('held_at');
    }

    private function pdf(string $bytes, string $filename): HttpResponse
    {
        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$filename.'"']);
    }
}
