<?php

namespace App\Http\Controllers\Chat;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Actions\CaptureLead;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A signed-in buyer tapped "Chat on WhatsApp" or "Call" (TDD M11: WhatsApp handoff). The
 * tap goes straight to WhatsApp or the dialler; this records the lead alongside it.
 */
class LeadIntentController extends Controller
{
    public function __invoke(Request $request, CaptureLead $capture): Response
    {
        $data = $request->validate([
            'vehicle' => ['required_without:lot', 'nullable', 'string', 'size:26'],
            'lot' => ['required_without:vehicle', 'nullable', 'string', 'max:120'],
            'source' => ['required', 'in:whatsapp,call'],
        ]);

        $vehicle = filled($data['vehicle'] ?? null) ? Vehicle::query()->marketplace()->where('vehicles.ulid', strtolower($data['vehicle']))->first() : null;
        $lot = $vehicle->lot ?? Lot::where('slug', $data['lot'] ?? '')->where('status', LotStatus::Active)->first();

        if ($lot !== null && ! $request->user()->hasLotRole($lot)) {
            $capture->run($lot, $request->user(), LeadSource::from($data['source']), $vehicle);
        }

        return response()->noContent();
    }
}
