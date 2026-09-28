<?php

namespace App\Http\Controllers\Account;

use App\Domain\Finance\Actions\SaveBudget;
use App\Domain\Inventory\Models\Vehicle;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** "What can I afford?" (design 08, TDD M10). Anyone can use it; saving needs an account. */
class BudgetController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('Marketplace/Budget', [
            'saved' => $request->user()?->budget?->toClient(),
            'finance' => self::finance(),
        ]);
    }

    public function update(Request $request, SaveBudget $save): RedirectResponse
    {
        $data = $request->validate([
            'monthly_income' => ['required', 'integer', 'min:1', 'max:10000000000'],
            'monthly_commitments' => ['nullable', 'integer', 'min:0', 'max:10000000000'],
            'deposit' => ['nullable', 'integer', 'min:0', 'max:10000000000'],
            'tenor_months' => ['required', 'integer', Rule::in(config('lotlink.finance.tenors'))],
            'interest_rate' => ['required', 'numeric', 'min:0', 'max:99'],
        ]);

        $budget = $save->run($request->user(), $data);

        return back()->with('success', 'Budget saved. Cars up to '.number_format($budget->max_price / 100).' naira are tagged "Within budget".');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->budget()->delete();

        return back()->with('success', 'Budget removed.');
    }

    /** How many live cars a price covers, for "Show 31 cars within budget". */
    public function count(Request $request): JsonResponse
    {
        $max = (int) $request->validate(['max' => ['required', 'integer', 'min:0']])['max'];

        return response()->json([
            'count' => Vehicle::query()->marketplace()->whereNotNull('vehicles.price')->where('vehicles.price', '<=', $max * 100)->count(),
        ]);
    }

    /** Rates and defaults for the calculators, labelled as estimates on screen. @return array<string, mixed> */
    public static function finance(): array
    {
        /** @var array<string, mixed> $finance */
        $finance = config('lotlink.finance');

        return array_intersect_key($finance, array_flip(['affordability_ratio', 'interest_rate', 'deposit_percent', 'tenor_months', 'tenors']));
    }
}
