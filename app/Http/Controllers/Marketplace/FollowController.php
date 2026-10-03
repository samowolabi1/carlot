<?php

namespace App\Http\Controllers\Marketplace;

use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Follow a seller for new stock (TDD M5). */
class FollowController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Account/Following', [
            'lots' => $request->user()->followedLots()->where('status', LotStatus::Active)->orderBy('name')->get()
                ->map(fn (Lot $lot) => [...collect(MarketplacePresenter::lot($lot))->only(['slug', 'url', 'name', 'initials', 'logo_url', 'city', 'verified'])->all()]),
        ])->withViewData(['meta' => ['title' => 'Sellers I follow', 'robots' => 'noindex']]);
    }

    public function store(Request $request, Lot $lot): RedirectResponse
    {
        abort_unless($lot->status === LotStatus::Active, 404);
        $lot->followers()->syncWithoutDetaching([$request->user()->id]);

        return back()->with('success', "Following {$lot->name}. We'll message you when they list new cars.");
    }

    public function destroy(Request $request, Lot $lot): RedirectResponse
    {
        $lot->followers()->detach($request->user()->id);

        return back()->with('success', "You've unfollowed {$lot->name}.");
    }
}
