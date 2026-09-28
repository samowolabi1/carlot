<?php

namespace App\Domain\LotManager\Models;

use App\Domain\LotManager\Enums\DocumentStatus;
use App\Domain\LotManager\Enums\DocumentType;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A paper or item that goes with the car: customs papers, plate number, spare key and so on
 * (TDD M19: papers and handover). Scans live on the private disk.
 *
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property int $sales_order_id
 * @property DocumentType $type
 * @property string|null $label
 * @property bool $mandatory
 * @property DocumentStatus $status
 * @property string|null $file_path
 * @property Carbon|null $received_at
 * @property Carbon|null $handed_over_at
 */
class OrderDocument extends Model
{
    use BelongsToLot, HasUlids, StoresUtc;

    public const DISK = 'local';

    protected $fillable = ['lot_id', 'sales_order_id', 'type', 'label', 'mandatory', 'status', 'file_path', 'received_at', 'handed_over_at'];

    protected $hidden = ['id', 'lot_id', 'sales_order_id', 'file_path'];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'mandatory' => 'boolean',
            'received_at' => 'datetime',
            'handed_over_at' => 'datetime',
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

    public function name(): string
    {
        return $this->label ?: $this->type->label();
    }

    public function isIn(): bool
    {
        return $this->status !== DocumentStatus::Pending;
    }
}
