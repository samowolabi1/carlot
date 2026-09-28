<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Inventory\Actions\ResolveVehicleModel;
use App\Domain\Inventory\Models\Make;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Inventory\Support\VinDecoder;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class VinDecodeController extends Controller
{
    public function __invoke(Request $request, Lot $lot, VinDecoder $decoder, ResolveVehicleModel $models): JsonResponse
    {
        Gate::authorize('create', [Vehicle::class, $lot]);

        $data = $request->validate(['vin' => ['required', 'string', 'regex:/^[A-HJ-NPR-Z0-9]{17}$/i']], [
            'vin.regex' => 'A VIN has 17 letters and numbers, and never uses I, O or Q.',
        ]);

        try {
            $decoded = $decoder->decode(strtoupper($data['vin']));
        } catch (ConnectionException|RequestException) {
            return response()->json(['found' => false, 'message' => 'The VIN service is not responding. Choose the make and model instead.'], 503);
        }

        if ($decoded === null) {
            return response()->json(['found' => false, 'message' => "We couldn't find that VIN. Check it, or choose the make and model."]);
        }

        $make = $decoded->make ? Make::where('slug', Str::slug($decoded->make))->first() : null;
        $model = $make ? $models->match($make, $decoded->model) : null;

        return response()->json([
            'found' => true,
            'decoded' => $decoded->toArray(),
            'make_id' => $make?->id,
            'vehicle_model_id' => $model?->id,
            'model_name' => $model !== null ? $model->name : $decoded->model,
        ]);
    }
}
