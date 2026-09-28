<?php

namespace App\Http\Controllers\Dealer\Manager;

use App\Domain\LotManager\Actions\SyncOfflineItems;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Replays the phone's offline queue (TDD M19: Offline mode). */
class SyncController extends Controller
{
    public function __invoke(Request $request, Lot $lot, SyncOfflineItems $sync): JsonResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'max:100'],
            'items.*.type' => ['required', Rule::in(SyncOfflineItems::TYPES)],
            'items.*.client_uuid' => ['required', 'uuid'],
            'items.*.data' => ['required', 'array'],
        ]);

        return response()->json(['results' => $sync->run($lot, $request->user(), $data['items'])]);
    }
}
