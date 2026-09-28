<?php

namespace App\Domain\Finance\Models;

use App\Domain\Accounts\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A buyer's saved budget. max_price powers the "Within budget" tags (TDD M10).
 *
 * @property int $id
 * @property int $user_id
 * @property int $monthly_income
 * @property int $monthly_commitments
 * @property int $deposit
 * @property int $tenor_months
 * @property string $interest_rate
 * @property int $max_price
 * @property string $currency
 */
class Budget extends Model
{
    protected $fillable = ['user_id', 'monthly_income', 'monthly_commitments', 'deposit', 'tenor_months', 'interest_rate', 'max_price', 'currency'];

    protected $hidden = ['id', 'user_id'];

    protected function casts(): array
    {
        return [
            'monthly_income' => 'integer',
            'monthly_commitments' => 'integer',
            'deposit' => 'integer',
            'tenor_months' => 'integer',
            'interest_rate' => 'decimal:2',
            'max_price' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whole naira, for the browser. @return array<string, int|float> */
    public function toClient(): array
    {
        return [
            'monthly_income' => intdiv($this->monthly_income, 100),
            'monthly_commitments' => intdiv($this->monthly_commitments, 100),
            'deposit' => intdiv($this->deposit, 100),
            'tenor_months' => $this->tenor_months,
            'interest_rate' => (float) $this->interest_rate,
            'max_price' => intdiv($this->max_price, 100),
        ];
    }
}
