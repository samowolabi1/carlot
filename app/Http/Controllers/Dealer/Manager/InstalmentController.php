<?php

namespace App\Http\Controllers\Dealer\Manager;

use App\Domain\LotManager\Actions\SetInstalmentPlan;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** PUT /orders/{o}/instalments (TDD M19): set, replace or remove an order's instalment plan. */
class InstalmentController extends Controller
{
    public function update(Request $request, Lot $lot, SalesOrder $order, SetInstalmentPlan $plan): RedirectResponse
    {
        Gate::authorize('cancel', $order); // owners and managers set payment terms
        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:'.SetInstalmentPlan::MAX],
            'first_due' => ['required', 'date'],
            'frequency' => ['required', Rule::in(array_keys(SetInstalmentPlan::FREQUENCIES))],
        ]);

        $plan->run($order, $request->user(), (int) $data['count'], $data['first_due'], $data['frequency']);

        return back()->with('success', 'Instalment plan saved. The customer gets a reminder 3 days before each one and on the day.');
    }

    public function destroy(Request $request, Lot $lot, SalesOrder $order, SetInstalmentPlan $plan): RedirectResponse
    {
        Gate::authorize('cancel', $order);
        $plan->clear($order, $request->user());

        return back()->with('success', 'Instalment plan removed.');
    }
}
