<?php

namespace App\Http\Middleware;

use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Support\CurrentLot;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For /dealer/{lot}/... routes: checks the user belongs to the lot, then makes it
 * the current lot so BelongsToLot scopes every query to it.
 */
class SetCurrentLot
{
    public function __construct(private readonly CurrentLot $currentLot) {}

    public function handle(Request $request, Closure $next): Response
    {
        $lot = $request->route('lot');

        if (! $lot instanceof Lot) {
            abort(404);
        }

        abort_unless($request->user()?->can('view', $lot), 403);

        $this->currentLot->set($lot);

        return $next($request);
    }
}
