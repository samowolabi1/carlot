<?php

namespace App\Domain\Trust\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\StoresUtc;
use App\Domain\Trust\Enums\InspectorType;
use App\Domain\Trust\Support\InspectionChecklist;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * A 40-point inspection of a car (TDD M14). The newest one is the car's current report
 * (`vehicles.inspection_id`); third-party ones are signed by a registered inspector.
 *
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property int $vehicle_id
 * @property InspectorType $inspector_type
 * @property int|null $inspector_id
 * @property string $inspector_name
 * @property array<string, array{status: string, note?: string|null}> $checklist
 * @property array<string, list<string>>|null $photos
 * @property int $score
 * @property string|null $summary
 * @property string|null $report_path
 * @property Carbon|null $signed_at
 * @property Carbon|null $created_at
 */
class Inspection extends Model
{
    use BelongsToLot, HasUlids, StoresUtc;

    public const MAX_PHOTOS = 12;

    protected $fillable = ['lot_id', 'vehicle_id', 'inspector_type', 'inspector_id', 'inspector_name', 'checklist', 'photos', 'score', 'summary', 'report_path', 'signed_at'];

    protected $hidden = ['id', 'lot_id', 'vehicle_id', 'inspector_id', 'report_path'];

    protected function casts(): array
    {
        return [
            'inspector_type' => InspectorType::class,
            'checklist' => 'array',
            'photos' => 'array',
            'score' => 'integer',
            'signed_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    public function isIndependent(): bool
    {
        return $this->inspector_type === InspectorType::ThirdParty && $this->signed_at !== null;
    }

    /** @return list<array{key: string, label: string, result: string, result_label: string, issues: list<array{label: string, result: string, note: string|null}>}> */
    public function summaryRows(): array
    {
        return InspectionChecklist::summary($this->checklist);
    }

    /** @return list<array{item: string, label: string, url: string}> */
    public function photoUrls(): array
    {
        $disk = Storage::disk(config('lotlink.media_disk'));

        return collect($this->photos ?? [])
            ->flatMap(fn (array $paths, string $item) => collect($paths)->map(fn (string $path) => [
                'item' => $item,
                'label' => InspectionChecklist::label($item) ?? 'General',
                'url' => $disk->url($path),
            ]))
            ->values()->all();
    }
}
