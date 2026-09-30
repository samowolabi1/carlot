<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Actions\RecordVehicleCost;
use App\Domain\LotManager\Enums\CostType;
use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Models\VehicleCost;
use App\Domain\LotManager\Support\Profit;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Fields;
use App\Domain\Support\Money;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Car costs and profit (TDD M19): owners and managers only (LotPolicy::viewCosts). */
class VehicleCostController extends Controller
{
    public function index(Request $request, Lot $lot, Vehicle $vehicle): Response
    {
        Gate::authorize('viewCosts', $lot);
        $vehicle->loadMissing(['make', 'model']);
        $costs = VehicleCost::query()->where('vehicle_id', $vehicle->id)->with('creator')->orderByDesc('incurred_at')->orderByDesc('id')->get();
        $order = SalesOrder::query()->where('vehicle_id', $vehicle->id)->where('status', '!=', OrderStatus::Cancelled)->latest('id')->first();
        $total = (int) $costs->sum('amount');

        return Inertia::render('Dealer/Vehicles/Costs', [
            'vehicle' => ['ulid' => $vehicle->ulid, 'title' => $vehicle->title() ?: 'Untitled car', 'price' => $vehicle->formattedPrice(), 'status' => $vehicle->status->label()],
            'costs' => $costs->map(fn (VehicleCost $c) => [
                'ulid' => $c->ulid,
                'type' => $c->type->label(),
                'amount' => $c->money(),
                'supplier' => $c->supplier,
                'note' => $c->note,
                'date' => $c->incurred_at->format('j M Y'),
                'by' => $c->creator?->name,
                'receipt_url' => $c->receipt_path ? route('dealer.vehicles.costs.receipt', [$lot, $vehicle, $c]) : null,
            ]),
            'total' => Money::format($total, $vehicle->currency),
            'profit' => $this->profit($vehicle, $order, $total),
            'types' => CostType::options(),
        ]);
    }

    /**
     * Profit on the car's order (sold or in progress), or at its list price while unsold.
     *
     * @return array{revenue: string, costs: string, profit: string, margin: ?float, order_no: ?string, sold: bool, negative: bool}|null
     */
    private function profit(Vehicle $vehicle, ?SalesOrder $order, int $total): ?array
    {
        $currency = $vehicle->currency;
        $figures = $order ? Profit::forOrder($order) : ($vehicle->price ? ['revenue' => (int) $vehicle->price, 'costs' => $total, 'profit' => (int) $vehicle->price - $total, 'margin' => round(((int) $vehicle->price - $total) / (int) $vehicle->price * 100, 1)] : null);

        if ($figures === null) {
            return null;
        }

        return [
            'revenue' => (string) Money::format($figures['revenue'], $currency),
            'costs' => (string) Money::format($figures['costs'], $currency),
            'profit' => (string) Money::format(abs($figures['profit']), $currency),
            'margin' => $figures['margin'],
            'order_no' => $order?->order_no,
            'sold' => $order?->status === OrderStatus::Delivered,
            'negative' => $figures['profit'] < 0,
        ];
    }

    public function store(Request $request, Lot $lot, Vehicle $vehicle, RecordVehicleCost $record): RedirectResponse
    {
        Gate::authorize('viewCosts', $lot);
        $request->merge(['amount' => Fields::cleanMoney($request->input('amount'))]);
        $data = $request->validate([
            'type' => ['required', Rule::enum(CostType::class)],
            'amount' => Fields::money(),
            'supplier' => Fields::businessName(required: false),
            'note' => ['nullable', 'string', 'max:500'],
            'incurred_at' => ['required', 'date', 'before_or_equal:today'],
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $record->run($vehicle, $request->user(), [...$data, 'amount' => Money::fromMajor((int) $data['amount'])], $request->file('receipt'));

        return back()->with('success', 'Cost added.');
    }

    public function destroy(Request $request, Lot $lot, Vehicle $vehicle, VehicleCost $cost, RecordVehicleCost $record): RedirectResponse
    {
        Gate::authorize('viewCosts', $lot);
        abort_unless($cost->vehicle_id === $vehicle->id, 404);
        $record->delete($cost, $request->user());

        return back()->with('success', 'Cost removed.');
    }

    public function receipt(Lot $lot, Vehicle $vehicle, VehicleCost $cost): StreamedResponse
    {
        Gate::authorize('viewCosts', $lot);
        abort_unless($cost->vehicle_id === $vehicle->id && $cost->receipt_path !== null, 404);
        abort_unless(Storage::disk(VehicleCost::DISK)->exists($cost->receipt_path), 404);

        return Storage::disk(VehicleCost::DISK)->download($cost->receipt_path);
    }
}
