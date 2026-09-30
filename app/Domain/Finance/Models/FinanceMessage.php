<?php

namespace App\Domain\Finance\Models;

use App\Domain\Accounts\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A note between the buyer and the lender on one application (with an optional document on the private disk),
 * or a system line recording what happened. The lot never sees these.
 *
 * @property int $id
 * @property string $ulid
 * @property int $finance_application_id
 * @property int|null $user_id
 * @property string $side buyer | lender | system
 * @property string $body
 * @property string|null $attachment_path
 * @property string|null $attachment_name
 * @property Carbon $created_at
 */
class FinanceMessage extends Model
{
    use HasUlids;

    public const DISK = 'local';

    public const BUYER = 'buyer';

    public const LENDER = 'lender';

    public const SYSTEM = 'system';

    protected $fillable = ['finance_application_id', 'user_id', 'side', 'body', 'attachment_path', 'attachment_name'];

    protected $hidden = ['id', 'finance_application_id', 'user_id', 'attachment_path'];

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<FinanceApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(FinanceApplication::class, 'finance_application_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
