<?php

namespace App\Domain\Trust\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Trust\Enums\InspectorType;
use App\Domain\Trust\Models\Inspection;
use App\Domain\Trust\Support\InspectionChecklist;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Records a 40-point inspection and makes it the car's current report (TDD M14). The seller's own
 * inspections show "Inspected"; a registered inspector's are signed and show "Independently
 * inspected". Photos are re-encoded to WebP, which drops EXIF location.
 */
class SaveInspection
{
    /**
     * @param  array<string, array{status: string, note?: string|null}>  $checklist
     * @param  array<string, list<UploadedFile>>  $photos  keyed by checklist item
     */
    public function run(Vehicle $vehicle, User $by, InspectorType $type, array $checklist, ?string $summary = null, array $photos = [], ?string $inspectorName = null): Inspection
    {
        $clean = [];
        foreach (InspectionChecklist::keys() as $key) {
            $clean[$key] = ['status' => $checklist[$key]['status'], 'note' => filled($checklist[$key]['note'] ?? null) ? trim((string) $checklist[$key]['note']) : null];
        }

        $independent = $type === InspectorType::ThirdParty;
        $name = $independent
            ? trim($by->name.($by->inspector_company ? ', '.$by->inspector_company : ''))
            : ($inspectorName ?: (string) $by->name);

        return DB::transaction(function () use ($vehicle, $by, $type, $clean, $summary, $photos, $independent, $name): Inspection {
            $inspection = Inspection::create([
                'lot_id' => $vehicle->lot_id,
                'vehicle_id' => $vehicle->id,
                'inspector_type' => $type,
                'inspector_id' => $by->id,
                'inspector_name' => $name,
                'checklist' => $clean,
                'score' => InspectionChecklist::score($clean),
                'summary' => $summary ?: null,
                'signed_at' => $independent ? now() : null,
            ]);

            $inspection->forceFill(['photos' => $this->storePhotos($inspection, $photos) ?: null])->save();

            // A seller's own check never hides a signed independent report; it stays in the history.
            $current = $vehicle->inspection_id ? Inspection::withoutGlobalScopes()->find($vehicle->inspection_id) : null;
            if ($independent || ! $current?->isIndependent()) {
                // save() so the search index picks up the "Inspected" badge.
                $vehicle->inspection_id = $inspection->id;
                $vehicle->save();
            }

            AuditLog::record('vehicle.inspected', $vehicle, ['score' => $inspection->score, 'type' => $type->value], $by, $vehicle->lot_id);

            return $inspection;
        });
    }

    /**
     * @param  array<string, list<UploadedFile>>  $photos
     * @return array<string, list<string>>
     */
    private function storePhotos(Inspection $inspection, array $photos): array
    {
        $disk = Storage::disk(config('lotlink.media_disk'));
        $manager = new ImageManager(new Driver, autoOrientation: true);
        $stored = [];
        $count = 0;

        foreach ($photos as $item => $files) {
            foreach ($files as $file) {
                if ($count >= Inspection::MAX_PHOTOS || InspectionChecklist::label($item) === null) {
                    break 2;
                }

                $path = "inspections/{$inspection->ulid}/".Str::lower((string) Str::ulid()).'.webp';
                $image = $manager->read($file->getRealPath())->scaleDown(width: 1200);
                $disk->put($path, (string) $image->toWebp(quality: 78), ['visibility' => 'public', 'ContentType' => 'image/webp']);
                $stored[$item][] = $path;
                $count++;
            }
        }

        return $stored;
    }
}
