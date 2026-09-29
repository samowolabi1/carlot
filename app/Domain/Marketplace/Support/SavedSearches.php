<?php

namespace App\Domain\Marketplace\Support;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Enums\BodyType;
use App\Domain\Inventory\Enums\FuelType;
use App\Domain\Inventory\Enums\Transmission;
use App\Domain\Inventory\Enums\VehicleCondition;
use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\VehicleModel;
use App\Domain\Marketplace\Models\SavedSearch;
use App\Domain\Marketplace\Search\SearchCriteria;
use Illuminate\Http\Request;

/**
 * Turning searches into saved filters and back. The buyer's location is never saved
 * (TDD privacy), so "near me" and distance filters are left out.
 */
final class SavedSearches
{
    private const DROP = ['lat', 'lng', 'radius', 'sort', 'page'];

    /** @return array<string, mixed> the filters worth saving, in a stable order */
    public static function filters(SearchCriteria $criteria): array
    {
        $filters = array_filter(
            array_diff_key($criteria->toArray(), array_flip(self::DROP)),
            fn ($value) => $value !== null && $value !== [] && $value !== '',
        );
        ksort($filters);

        return $filters;
    }

    /** @param array<string, mixed> $filters */
    public static function criteria(array $filters): SearchCriteria
    {
        return SearchCriteria::fromRequest(Request::create('/cars', 'GET', $filters));
    }

    /** The ulid of the user's saved search for exactly these filters, if any. */
    public static function matching(User $user, SearchCriteria $criteria): ?string
    {
        $filters = self::filters($criteria);

        if ($filters === []) {
            return null;
        }

        return SavedSearch::where('user_id', $user->id)->get()
            ->first(fn (SavedSearch $s) => self::normalise($s->filters) == $filters)?->ulid;
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public static function normalise(array $filters): array
    {
        return self::filters(self::criteria($filters));
    }

    /** "Toyota SUVs under ₦15m in Ikeja" @param array<string, mixed> $filters */
    public static function describe(array $filters): string
    {
        $c = self::criteria($filters);
        $make = count($c->makeIds) === 1 ? Make::whereKey($c->makeIds[0])->value('name') : (count($c->makeIds) > 1 ? count($c->makeIds).' makes' : null);
        $model = $c->modelId ? VehicleModel::whereKey($c->modelId)->value('name') : null;
        $body = count($c->bodyTypes) === 1 ? BodyType::from($c->bodyTypes[0])->label().'s' : 'cars';

        $parts = [trim(implode(' ', array_filter([$c->query ? '"'.$c->query.'"' : null, $make, $model, $model ? null : $body])))];
        $parts[] = match (true) {
            $c->priceMin !== null && $c->priceMax !== null => 'from '.self::short($c->priceMin).' to '.self::short($c->priceMax),
            $c->priceMax !== null => 'under '.self::short($c->priceMax),
            $c->priceMin !== null => 'over '.self::short($c->priceMin),
            default => null,
        };
        $parts[] = $c->yearMin ? $c->yearMin.' or newer' : null;
        $parts[] = $c->transmission ? strtolower(Transmission::from($c->transmission)->label()) : null;
        $parts[] = count($c->conditions) === 1 ? strtolower(VehicleCondition::from($c->conditions[0])->label()) : null;
        $parts[] = count($c->fuels) === 1 ? strtolower(FuelType::from($c->fuels[0])->label()) : null;
        $parts[] = $c->mileageMax ? 'under '.number_format($c->mileageMax).' km' : null;
        $parts[] = $c->city ? 'in '.$c->city : null;

        return ucfirst(mb_substr(implode(' ', array_filter($parts)), 0, 120));
    }

    /** ₦15m, ₦7.5m, ₦800k */
    private static function short(int $kobo): string
    {
        $naira = intdiv($kobo, 100);

        return match (true) {
            $naira >= 1_000_000 => '₦'.rtrim(rtrim(number_format($naira / 1_000_000, 1), '0'), '.').'m',
            $naira >= 1_000 => '₦'.number_format($naira / 1_000).'k',
            default => '₦'.number_format($naira),
        };
    }
}
