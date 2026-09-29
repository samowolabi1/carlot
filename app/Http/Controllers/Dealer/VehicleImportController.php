<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Inventory\Imports\ImportTemplate;
use App\Domain\Inventory\Imports\VehicleRow;
use App\Domain\Inventory\Jobs\ImportVehicles;
use App\Domain\Inventory\Models\VehicleImport;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Bulk import (TDD M3; Enterprise in the spec): template, upload, and results row by row. */
class VehicleImportController extends Controller
{
    public function index(Lot $lot): Response
    {
        Gate::authorize('update', $lot);

        return Inertia::render('Dealer/Vehicles/Import', [
            'allowed' => $lot->planAllows('bulk_import'),
            'columns' => VehicleRow::COLUMNS,
            'maxRows' => VehicleImport::MAX_ROWS,
            'imports' => VehicleImport::query()->with('user')->latest()->limit(10)->get()->map(fn (VehicleImport $i) => [
                'ulid' => $i->ulid,
                'name' => $i->original_name,
                'status' => $i->status,
                'total' => $i->total_rows,
                'imported' => $i->imported_rows,
                'errors' => $i->errors ?? [],
                'by' => $i->user?->name,
                'when' => $i->created_at->copy()->setTimezone($lot->timezone)->format('j M, H:i'),
            ]),
        ]);
    }

    public function template(Lot $lot): BinaryFileResponse
    {
        Gate::authorize('update', $lot);

        return Excel::download(new ImportTemplate, 'lotlink-stock-template.xlsx');
    }

    public function store(Request $request, Lot $lot): RedirectResponse
    {
        Gate::authorize('update', $lot);
        abort_unless($lot->planAllows('bulk_import'), 403, 'Bulk import is on the Enterprise plan.');

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:5120'],
        ], ['file.mimes' => 'Upload the template as .xlsx or .csv.', 'file.max' => 'The file must be 5 MB or smaller.']);

        $file = $request->file('file');
        $path = $file->storeAs("imports/{$lot->ulid}", Str::lower((string) Str::ulid()).'.'.($file->getClientOriginalExtension() ?: 'csv'), VehicleImport::DISK);

        $import = VehicleImport::create([
            'lot_id' => $lot->id,
            'user_id' => $request->user()->id,
            'file_path' => (string) $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 160),
            'status' => 'queued',
        ]);
        ImportVehicles::dispatch($import->id);

        return back()->with('success', 'File uploaded. Cars are added as drafts; this page shows any rows to fix.');
    }
}
