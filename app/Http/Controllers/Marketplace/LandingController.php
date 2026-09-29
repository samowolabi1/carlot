<?php

namespace App\Http\Controllers\Marketplace;

use App\Domain\Marketplace\Search\SearchCriteria;
use App\Domain\Marketplace\Search\VehicleSearch;
use App\Domain\Seo\Landing;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Response;

/**
 * SEO landing pages (TDD M18): the search page with filters taken from the path, a unique
 * title, heading and intro, and links to narrower pages. Pages with no stock are noindex.
 */
class LandingController extends Controller
{
    public function __invoke(Request $request, VehicleSearch $search, string $first, ?string $second = null, ?string $third = null): Response
    {
        $landing = Landing::resolve([$first, $second, $third]) ?? abort(404);
        // TDD performance: SEO page figures are cached for 10 minutes.
        [$stats, $related] = Cache::remember('landing:'.md5($landing->canonical()), now()->addMinutes(10), fn () => [$landing->stats(), $landing->related()]);

        $filtered = $request->query() !== [];

        // The path's filters win over the query string; other filters (price, sort…) still apply.
        $request->merge($landing->filters());
        $criteria = SearchCriteria::fromRequest($request);

        return SearchController::render($request, $search, $criteria, [
            'landing' => [
                'heading' => $landing->heading(),
                'intro' => $landing->intro($stats),
                'related' => $related,
                'url' => $landing->canonical(),
            ],
        ], [
            'title' => $landing->title(),
            'description' => $landing->intro($stats),
            'url' => $landing->canonical(),
            'robots' => $stats['count'] === 0 ? 'noindex, follow' : ($filtered ? 'noindex, follow' : null),
        ]);
    }
}
