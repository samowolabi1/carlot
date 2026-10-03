<?php

namespace App\Domain\Lots\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotBankAccount;
use App\Domain\Lots\Notifications\BankDetailsChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Adds, edits, removes and picks the default bank account a seller shares with customers.
 * Money goes where these say, so every change is audit-logged and the owner and managers are told.
 */
class SaveBankAccount
{
    /** @param  array{bank_name: string, account_number: string, account_name: string, is_default?: bool}  $data */
    public function save(Lot $lot, User $by, array $data, ?LotBankAccount $account = null): LotBankAccount
    {
        $account = DB::transaction(function () use ($lot, $by, $data, $account) {
            $count = LotBankAccount::withoutGlobalScopes()->where('lot_id', $lot->id)->lockForUpdate()->count();

            if ($account === null && $count >= LotBankAccount::MAX) {
                throw ValidationException::withMessages(['account_number' => 'You can keep up to '.LotBankAccount::MAX.' accounts. Remove one first.']);
            }

            $before = $account?->only(['bank_name', 'account_number', 'account_name', 'is_default']);
            $account ??= new LotBankAccount(['lot_id' => $lot->id]);
            $account->fill([
                'bank_name' => trim($data['bank_name']),
                'account_number' => preg_replace('/\D/', '', $data['account_number']) ?? '',
                'account_name' => trim($data['account_name']),
                'is_default' => ($data['is_default'] ?? false) || $count === 0 || ($account->exists && $account->is_default),
                'updated_by' => $by->id,
            ])->save();

            if ($account->is_default) {
                LotBankAccount::withoutGlobalScopes()->where('lot_id', $lot->id)->whereKeyNot($account->id)->update(['is_default' => false]);
            }

            AuditLog::record($before === null ? 'lot.bank_account_added' : 'lot.bank_account_changed', $account, [
                'before' => $before,
                'after' => $account->only(['bank_name', 'account_number', 'account_name', 'is_default']),
            ], $by, $lot->id);

            return $account;
        });

        $this->tell($lot, $by, "{$account->bank_name} ".self::mask($account->account_number).' ('.$account->account_name.') was '.($account->wasRecentlyCreated ? 'added' : 'updated'));

        return $account;
    }

    public function remove(Lot $lot, User $by, LotBankAccount $account): void
    {
        DB::transaction(function () use ($lot, $by, $account) {
            $account->delete();

            if ($account->is_default) {
                LotBankAccount::withoutGlobalScopes()->where('lot_id', $lot->id)->orderBy('id')->first()?->update(['is_default' => true]);
            }

            AuditLog::record('lot.bank_account_removed', $account, ['before' => $account->only(['bank_name', 'account_number', 'account_name'])], $by, $lot->id);
        });

        $this->tell($lot, $by, "{$account->bank_name} ".self::mask($account->account_number).' was removed');
    }

    public static function mask(string $number): string
    {
        return '••••'.substr($number, -4);
    }

    private function tell(Lot $lot, User $by, string $what): void
    {
        $people = $lot->members()->wherePivotIn('role', [LotRole::Owner->value, LotRole::Manager->value])->get();
        Notification::send($people, new BankDetailsChanged($lot, $what, (string) $by->name));
    }
}
