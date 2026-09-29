<?php

namespace App\Domain\Trust\Support;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Domain\Trust\Enums\CheckResult;
use App\Domain\Trust\Models\Inspection;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/** The inspection report as a PDF (TDD M14), rendered once and kept on the private disk. */
final class InspectionPdf
{
    public const DISK = 'local';

    /** @return string the path on the private disk */
    public static function path(Inspection $inspection): string
    {
        $disk = Storage::disk(self::DISK);

        if ($inspection->report_path !== null && $disk->exists($inspection->report_path)) {
            return $inspection->report_path;
        }

        $path = "inspections/{$inspection->ulid}.pdf";
        $disk->put($path, self::render($inspection));
        $inspection->forceFill(['report_path' => $path])->save();

        return $path;
    }

    public static function render(Inspection $inspection): string
    {
        $vehicle = Vehicle::withoutGlobalScopes()->withTrashed()->with(['make', 'model'])->findOrFail($inspection->vehicle_id);
        $lot = Lot::withTrashed()->findOrFail($inspection->lot_id);
        $tz = $lot->timezone;

        $groups = collect(InspectionChecklist::GROUPS)->map(function (array $group, string $key) use ($inspection) {
            $items = collect($group['items'])->map(function (string $label, string $item) use ($inspection) {
                $result = CheckResult::tryFrom($inspection->checklist[$item]['status'] ?? '') ?? CheckResult::Pass;

                return ['label' => $label, 'result' => $result->value, 'result_label' => $result->label(), 'note' => $inspection->checklist[$item]['note'] ?? null];
            })->values();
            $worst = $items->sortBy(fn (array $i) => CheckResult::from($i['result'])->points())->first();

            return ['label' => $group['label'], 'result' => $worst['result'], 'result_label' => $worst['result_label'], 'items' => $items->all()];
        })->values();

        return Pdf::loadView('pdf.inspection', [
            'inspection' => $inspection,
            'lot' => $lot,
            'car' => $vehicle->title(),
            'vinTail' => $vehicle->vinTail(),
            'mileage' => $vehicle->mileage_km !== null ? number_format($vehicle->mileage_km).' km' : null,
            'date' => $inspection->created_at?->copy()->setTimezone($tz)->format('j M Y'),
            'signedAt' => $inspection->signed_at?->copy()->setTimezone($tz)->format('j M Y, H:i'),
            'groups' => $groups,
            'url' => url($vehicle->publicPath()),
        ])->setPaper('a4')->setOption('isFontSubsettingEnabled', true)->output();
    }
}
