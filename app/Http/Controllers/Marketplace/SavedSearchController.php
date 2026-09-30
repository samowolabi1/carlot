<?php

namespace App\Http\Controllers\Marketplace;

use App\Domain\Marketplace\Actions\SaveSearch;
use App\Domain\Marketplace\Models\SavedSearch;
use App\Domain\Marketplace\Search\SearchCriteria;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Saved searches with alerts (TDD M4, design 12 "Saved search alerts"). */
class SavedSearchController extends Controller
{
    public function store(Request $request, SaveSearch $save): RedirectResponse
    {
        $data = $request->validate([
            'filters' => ['required', 'array', 'max:20'],
            'channel' => ['nullable', Rule::in(array_keys(SavedSearch::CHANNELS))],
        ]);

        $criteria = SearchCriteria::fromRequest(Request::create('/cars', 'GET', $data['filters']));
        $saved = $save->run($request->user(), $criteria, $data['channel'] ?? 'phone');

        return back()->with('success', "Search saved: \"{$saved->name}\". We'll tell you when a new car matches.");
    }

    public function update(Request $request, SavedSearch $savedSearch): RedirectResponse
    {
        abort_unless($savedSearch->user_id === $request->user()->id, 404);
        $data = $request->validate(['channel' => ['required', Rule::in(array_keys(SavedSearch::CHANNELS))]]);
        $savedSearch->update($data);

        return back()->with('success', 'Alert updated.');
    }

    public function destroy(Request $request, SavedSearch $savedSearch): RedirectResponse
    {
        abort_unless($savedSearch->user_id === $request->user()->id, 404);
        $savedSearch->delete();

        return back()->with('success', 'Search deleted.');
    }
}
