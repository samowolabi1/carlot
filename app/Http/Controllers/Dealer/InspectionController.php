<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Domain\Trust\Actions\SaveInspection;
use App\Domain\Trust\Enums\CheckResult;
use App\Domain\Trust\Enums\InspectorType;
use App\Domain\Trust\Models\Inspection;
use App\Domain\Trust\Support\InspectionChecklist;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MarketplacePresenter;
use App\Http\Requests\Trust\InspectionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** "Add inspection report" for a car in stock (TDD M14, seller API /vehicles/{v}/inspection). */
class InspectionController extends Controller
{
    public function create(Lot $lot, Vehicle $vehicle): Response
    {
        Gate::authorize('update', $vehicle);
        $vehicle->loadMissing(['make', 'model', 'inspection']);

        return Inertia::render('Dealer/Vehicles/Inspection', [
            'vehicle' => ['ulid' => $vehicle->ulid, 'title' => $vehicle->title() ?: 'Untitled car', 'status' => $vehicle->status->label()],
            ...self::form($vehicle->inspection, $lot->timezone),
        ]);
    }

    public function store(InspectionRequest $request, Lot $lot, Vehicle $vehicle, SaveInspection $save): RedirectResponse
    {
        Gate::authorize('update', $vehicle);

        $inspection = $save->run($vehicle, $request->user(), InspectorType::Dealer, $request->validated('checklist'), $request->validated('summary'), $request->photos(), $request->validated('inspector_name'));

        return to_route('dealer.vehicles.index', $lot)->with('success', "Inspection saved: {$inspection->score}/100. Buyers see it on the car page.");
    }

    /** @return array<string, mixed> the checklist shape both inspection forms use */
    public static function form(?Inspection $current, string $timezone): array
    {
        return [
            'groups' => collect(InspectionChecklist::GROUPS)->map(fn (array $g, string $key) => [
                'key' => $key,
                'label' => $g['label'],
                'items' => collect($g['items'])->map(fn (string $label, string $item) => ['key' => $item, 'label' => $label])->values(),
            ])->values(),
            'results' => collect(CheckResult::cases())->map(fn (CheckResult $r) => ['value' => $r->value, 'label' => $r->label()]),
            // The last report pre-fills the form, so a re-inspection only changes what changed.
            'previous' => $current ? [
                'checklist' => $current->checklist,
                'summary' => $current->summary,
                'report' => MarketplacePresenter::inspection($current, $timezone),
            ] : null,
            'maxPhotos' => Inspection::MAX_PHOTOS,
        ];
    }
}
