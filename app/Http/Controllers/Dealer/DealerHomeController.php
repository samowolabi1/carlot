<?php

namespace App\Http\Controllers\Dealer;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DealerHomeController extends Controller
{
    /** /dealer: open the user's first lot, or start onboarding if they have none. */
    public function __invoke(Request $request): RedirectResponse
    {
        $lot = $request->user()->lots()->orderBy('lot_members.created_at')->first();

        return $lot
            ? redirect()->route('dealer.dashboard', $lot)
            : redirect()->route('dealer.onboarding.start');
    }
}
