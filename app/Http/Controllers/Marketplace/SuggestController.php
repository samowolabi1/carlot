<?php

namespace App\Http\Controllers\Marketplace;

use App\Domain\Marketplace\Search\SearchSuggestions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The search box's suggestions as someone types (makes, models, places, lots, cars). Public, cached, JSON. */
class SuggestController extends Controller
{
    public function __invoke(Request $request, SearchSuggestions $suggestions): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'cars' => ['nullable', 'boolean'],
        ]);

        return response()
            ->json($suggestions->for((string) ($data['q'] ?? ''), $request->boolean('cars', true)))
            // Same answer for everyone for a minute: browsers and a CDN can reuse it.
            ->header('Cache-Control', 'public, max-age=60');
    }
}
