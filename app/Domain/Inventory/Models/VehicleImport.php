<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Concerns\BelongsToLot;
use App\Domain\Support\StoresUtc;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A bulk upload of stock (TDD M3): the file on the private disk, progress, and what went wrong
 * row by row. Imported cars start as drafts, since they still need photos.
 *
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property int|null $user_id
 * @property string $file_path
 * @property string $original_name
 * @property string $status queued, processing, done, failed
 * @property int $total_rows
 * @property int $imported_rows
 * @property list<array{row: int, messages: list<string>}>|null $errors
 * @property Carbon|null $finished_at
 * @property Carbon $created_at
 */
class VehicleImport extends Model
{
    use BelongsToLot, HasUlids, StoresUtc;

    public const DISK = 'local';

    public const MAX_ROWS = 500;

    protected $fillable = ['lot_id', 'user_id', 'file_path', 'original_name', 'status', 'total_rows', 'imported_rows', 'errors', 'finished_at'];

    protected $hidden = ['id', 'lot_id', 'user_id', 'file_path'];

    protected function casts(): array
    {
        return ['errors' => 'array', 'total_rows' => 'integer', 'imported_rows' => 'integer', 'finished_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
