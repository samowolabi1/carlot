<?php

namespace App\Domain\Advertising\Support;

use App\Domain\Advertising\Enums\AdPlacement;
use App\Domain\Advertising\Models\AdCampaign;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Marketplace\Search\SearchCriteria;
use Illuminate\Database\Eloquent\Builder;

/** Which adverts buyers see: the homepage banners, and the best-matching search banner. */
final class AdServer
{
    /** @return list<array<string, mixed>> the live homepage banners, in a fresh order each visit */
    public static function home(): array
    {
        return self::live(AdPlacement::HomeBanner)->inRandomOrder()->limit(AdPlacement::HomeBanner->slots())->get()
            ->map(fn (AdCampaign $c) => self::present($c))->values()->all();
    }

    /**
     * One search banner for this search: one aimed at what the buyer is looking for wins over a
     * general one; ties are picked at random.
     *
     * @return array<string, mixed>|null
     */
    public static function search(SearchCriteria $criteria): ?array
    {
        $best = self::live(AdPlacement::SearchBanner)->get()
            ->map(fn (AdCampaign $c) => [$c, self::score($c, $criteria)])
            ->filter(fn (array $pair) => $pair[1] !== null)
            ->shuffle()
            ->sortByDesc(fn (array $pair) => $pair[1])
            ->first();

        return $best ? self::present($best[0]) : null;
    }

    /** How well a banner's targeting fits the search: null if it doesn't, else the number of matching aims. */
    public static function score(AdCampaign $campaign, SearchCriteria $criteria): ?int
    {
        $aim = $campaign->targeting ?? [];
        $score = 0;

        if (! empty($aim['make_id'])) {
            if (! in_array((int) $aim['make_id'], $criteria->makeIds, true)) {
                return null;
            }
            $score++;
        }
        if (! empty($aim['body_type'])) {
            if (! in_array($aim['body_type'], $criteria->bodyTypes, true)) {
                return null;
            }
            $score++;
        }
        if (! empty($aim['city'])) {
            if ($criteria->city === null || strcasecmp(trim($criteria->city), trim((string) $aim['city'])) !== 0) {
                return null;
            }
            $score++;
        }

        return $score;
    }

    /** @return array<string, mixed> */
    public static function present(AdCampaign $c): array
    {
        return [
            'ulid' => $c->ulid,
            'placement' => $c->placement->value,
            'headline' => $c->headline,
            'subtext' => $c->subtext,
            'cta' => $c->cta->label(),
            'image' => $c->imageUrl(),
            'lot' => $c->lot->name,
            'logo' => $c->lot->logo_url,
            'url' => route('ads.click', $c->ulid),
        ];
    }

    /** @return Builder<AdCampaign> */
    private static function live(AdPlacement $placement): Builder
    {
        return AdCampaign::query()->live()->where('placement', $placement)
            ->whereHas('lot', fn (Builder $q) => $q->where('status', LotStatus::Active))
            ->with(['lot', 'vehicle.cover']);
    }
}
