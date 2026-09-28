<?php

namespace App\Http\Controllers\Marketplace;

use App\Domain\Finance\Support\FinanceCalculator;
use App\Domain\Inventory\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompareController extends Controller
{
    public const MAX = 3;

    /** /compare?ids=a,b,c */
    public function __invoke(Request $request): Response
    {
        $ulids = array_slice(array_unique(array_filter(explode(',', strtolower((string) $request->query('ids'))))), 0, self::MAX);

        $vehicles = Vehicle::query()
            ->marketplace()
            ->whereIn('ulid', $ulids)
            ->with(['make', 'model', 'lot', 'cover'])
            ->get()
            ->sortBy(fn (Vehicle $v) => array_search($v->ulid, $ulids, true))
            ->values();

        $monthly = $vehicles->mapWithKeys(fn (Vehicle $v) => [$v->id => $v->price ? FinanceCalculator::fromPrice(intdiv($v->price, 100))['monthly'] : null]);
        $lowestMonthly = $monthly->filter()->count() > 1 ? $monthly->filter()->min() : null;

        $best = fn (string $attr, bool $lowest) => $vehicles->pluck($attr)->filter(fn ($v) => $v !== null)->pipe(
            fn ($values) => $values->count() > 1 ? ($lowest ? $values->min() : $values->max()) : null,
        );

        return Inertia::render('Marketplace/Compare', [
            'cars' => $vehicles->map(fn (Vehicle $v) => [
                ...MarketplacePresenter::card($v),
                'rows' => [
                    'price' => ['value' => $v->formattedPrice(), 'best' => $v->price === $best('price', true)],
                    'monthly' => ['value' => $monthly[$v->id] ? '₦'.number_format($monthly[$v->id]).'/mo' : null, 'best' => $monthly[$v->id] !== null && $monthly[$v->id] === $lowestMonthly],
                    'year' => ['value' => $v->year, 'best' => $v->year === $best('year', false)],
                    'mileage' => ['value' => $v->mileage_km !== null ? number_format($v->mileage_km).' km' : null, 'best' => $v->mileage_km === $best('mileage_km', true)],
                    'engine' => ['value' => $v->engine_cc ? number_format($v->engine_cc / 1000, 1).'L' : null, 'best' => false],
                    'body' => ['value' => $v->body_type?->label(), 'best' => false],
                    'gearbox' => ['value' => $v->transmission?->label(), 'best' => false],
                    'fuel' => ['value' => $v->fuel?->label(), 'best' => false],
                    'condition' => ['value' => $v->condition?->label(), 'best' => false],
                    'duty' => ['value' => $v->duty_status?->value === 'paid' ? 'Paid' : ($v->duty_status?->value === 'unpaid' ? 'Not paid' : null), 'best' => false],
                    'lot' => ['value' => $v->lot->name.($v->lot->city ? ', '.$v->lot->city : ''), 'best' => false],
                ],
            ]),
            'terms' => FinanceCalculator::fromPrice(0),
        ])->withViewData(['meta' => ['title' => 'Compare cars', 'robots' => 'noindex']]);
    }
}
