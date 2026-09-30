<?php

namespace App\Http\Controllers\Dealer\Manager;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Actions\RecordWalkIn;
use App\Domain\LotManager\Models\WalkIn;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\WalkInRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The walk-in register (TDD M19). */
class WalkInController extends Controller
{
    public function index(Request $request, Lot $lot): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:60']]);

        $walkIns = WalkIn::query()->with(['customer', 'staff'])
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->whereHas('customer', fn (Builder $c) => $c
                ->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%')
                ->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $term).'%')))
            ->latest('visited_at')
            ->paginate(30)
            ->withQueryString();

        $cars = Vehicle::query()->with(['make', 'model'])
            ->whereIn('id', collect($walkIns->items())->pluck('vehicles_viewed')->flatten()->unique()->all())
            ->get()->keyBy('id');

        return Inertia::render('Dealer/Manager/WalkIns', [
            'walkIns' => $walkIns->through(fn (WalkIn $w) => Presenter::walkIn($w, $lot->timezone, $cars)),
            'filters' => ['q' => $filters['q'] ?? ''],
            // Closures: skipped when a live search reloads only the list.
            'stock' => fn () => Presenter::stock(),
            'options' => fn () => Presenter::options(),
        ]);
    }

    public function store(WalkInRequest $request, Lot $lot, RecordWalkIn $record): RedirectResponse
    {
        $walkIn = $record->run($lot, $request->user(), $request->action());

        return back()->with('success', $walkIn->follow_up_at
            ? 'Walk-in saved. We\'ll remind you to call back.'
            : 'Walk-in saved.');
    }
}
