<?php

namespace App\Http\Controllers\Trust;

use App\Domain\Trust\Actions\SaveInspection;
use App\Domain\Trust\Enums\InspectorType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dealer\InspectionController;
use App\Http\Presenters\DealsPresenter;
use App\Http\Presenters\MarketplacePresenter;
use App\Http\Requests\Trust\InspectionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A registered inspector's signed report on a marketplace car (TDD M14): shown as
 * "Independently inspected". Inspectors are marked by an admin.
 */
class IndependentInspectionController extends Controller
{
    public function create(Request $request, string $car): Response
    {
        abort_unless($request->user()->isInspector(), 403);
        $vehicle = DealsPresenter::car($car);
        $vehicle->loadMissing('inspection');

        return Inertia::render('Trust/Inspect', [
            'car' => MarketplacePresenter::card($vehicle),
            'inspector' => trim($request->user()->name.($request->user()->inspector_company ? ', '.$request->user()->inspector_company : '')),
            ...InspectionController::form($vehicle->inspection?->isIndependent() ? $vehicle->inspection : null, $vehicle->lot->timezone),
        ])->withViewData(['meta' => ['title' => 'Inspect '.$vehicle->title(), 'robots' => 'noindex']]);
    }

    public function store(InspectionRequest $request, string $car, SaveInspection $save): RedirectResponse
    {
        abort_unless($request->user()->isInspector(), 403);
        $vehicle = DealsPresenter::car($car);

        $inspection = $save->run($vehicle, $request->user(), InspectorType::ThirdParty, $request->validated('checklist'), $request->validated('summary'), $request->photos());

        return redirect($vehicle->publicPath())->with('success', "Signed report saved ({$inspection->score}/100). The car now shows Independently inspected.");
    }
}
