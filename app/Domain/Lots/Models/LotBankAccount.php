<?php

namespace App\Domain\Lots\Models;

use App\Domain\Lots\Concerns\BelongsToLot;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A bank account the seller shares with customers so they pay the seller directly. CarYard never
 * receives money for cars; its only payments are the seller's subscription. Owner-managed
 * through `SaveBankAccount` (audit-logged, the owner is told of every change).
 *
 * @property int $id
 * @property string $ulid
 * @property int $lot_id
 * @property string $bank_name
 * @property string $account_number
 * @property string $account_name
 * @property bool $is_default
 * @property int|null $updated_by
 */
class LotBankAccount extends Model
{
    use BelongsToLot, HasUlids;

    public const MAX = 3;

    /** Banks and wallets Nigerian lots commonly use (the field also takes any other name). */
    public const BANKS = [
        'Access Bank', 'Citibank', 'Ecobank', 'Fidelity Bank', 'First Bank', 'FCMB', 'Globus Bank', 'GTBank',
        'Heritage Bank', 'Jaiz Bank', 'Keystone Bank', 'Kuda', 'Lotus Bank', 'Moniepoint', 'Opay', 'Optimus Bank',
        'PalmPay', 'Parallex Bank', 'Polaris Bank', 'Premium Trust Bank', 'Providus Bank', 'Signature Bank',
        'Stanbic IBTC', 'Standard Chartered', 'Sterling Bank', 'SunTrust Bank', 'Taj Bank', 'Titan Trust Bank',
        'Union Bank', 'UBA', 'Unity Bank', 'Wema Bank', 'Zenith Bank',
    ];

    protected $fillable = ['lot_id', 'bank_name', 'account_number', 'account_name', 'is_default', 'updated_by'];

    protected $hidden = ['id', 'lot_id', 'updated_by'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** The account to show customers: the default one, else the first. */
    public static function preferredFor(int $lotId): ?self
    {
        return self::withoutGlobalScopes()->where('lot_id', $lotId)->orderByDesc('is_default')->orderBy('id')->first();
    }

    /** @return array{ulid: string, bank_name: string, account_number: string, account_name: string, is_default: bool} */
    public function present(): array
    {
        return [
            'ulid' => $this->ulid,
            'bank_name' => $this->bank_name,
            'account_number' => $this->account_number,
            'account_name' => $this->account_name,
            'is_default' => $this->is_default,
        ];
    }

    /** Ready to paste into WhatsApp or SMS. */
    public function shareText(string $lotName, ?string $amount = null, ?string $reference = null): string
    {
        return implode("\n", array_filter([
            "Pay {$lotName} directly:",
            "Bank: {$this->bank_name}",
            "Account number: {$this->account_number}",
            "Account name: {$this->account_name}",
            $amount ? "Amount: {$amount}" : null,
            $reference ? "Reference: {$reference}" : null,
            'Please send the transfer receipt once paid.',
        ]));
    }
}
