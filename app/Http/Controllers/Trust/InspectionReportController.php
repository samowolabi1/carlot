<?php

namespace App\Http\Controllers\Trust;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Trust\Models\Inspection;
use App\Domain\Trust\Support\InspectionPdf;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The PDF of a car's current inspection. Public while the car is on the marketplace (buyers
 * share it); otherwise only the lot's team can open it.
 */
class InspectionReportController extends Controller
{
    public function __invoke(Request $request, Inspection $inspection): StreamedResponse
    {
        $vehicle = Vehicle::withoutGlobalScopes()->withTrashed()->findOrFail($inspection->vehicle_id);
        $public = $vehicle->inspection_id === $inspection->id && $vehicle->isOnMarketplace();

        abort_unless($public || $request->user()?->can('update', $vehicle), 404);

        $filename = 'inspection-'.($vehicle->slug ?: $vehicle->ulid).'.pdf';

        return Storage::disk(InspectionPdf::DISK)->response(InspectionPdf::path($inspection), $filename, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
