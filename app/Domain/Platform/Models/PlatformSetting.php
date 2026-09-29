<?php

namespace App\Domain\Platform\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A platform-wide setting admins can change (e.g. `finance`), stored as JSON.
 *
 * @property string $key
 * @property array<string, mixed> $value
 * @property int|null $updated_by
 */
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
