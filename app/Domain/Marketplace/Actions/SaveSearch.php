<?php

namespace App\Domain\Marketplace\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Marketplace\Models\SavedSearch;
use App\Domain\Marketplace\Search\SearchCriteria;
use App\Domain\Marketplace\Support\SavedSearches;
use Illuminate\Validation\ValidationException;

/** "Save search" on the search page: the filters (never the buyer's location) and an alert channel. */
class SaveSearch
{
    public function run(User $user, SearchCriteria $criteria, string $channel = 'phone', ?string $name = null): SavedSearch
    {
        $filters = SavedSearches::filters($criteria);

        if ($filters === []) {
            throw ValidationException::withMessages(['filters' => 'Choose at least one filter, like a make or a price, to save a search.']);
        }

        if ($existing = SavedSearches::matching($user, $criteria)) {
            return SavedSearch::where('ulid', $existing)->firstOrFail();
        }

        if (SavedSearch::where('user_id', $user->id)->count() >= SavedSearch::MAX_PER_USER) {
            throw ValidationException::withMessages(['filters' => 'You can save up to '.SavedSearch::MAX_PER_USER.' searches. Delete one under Saved → Searches first.']);
        }

        return SavedSearch::create([
            'user_id' => $user->id,
            'name' => $name ?: SavedSearches::describe($filters),
            'filters' => $filters,
            'channel' => array_key_exists($channel, SavedSearch::CHANNELS) ? $channel : 'phone',
        ]);
    }
}
