<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Lots\Actions\SaveBankAccount;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotBankAccount;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Settings → Bank details: the accounts customers pay into. Owner only. */
class BankAccountController extends Controller
{
    public function store(Request $request, Lot $lot, SaveBankAccount $save): RedirectResponse
    {
        Gate::authorize('manageBankAccounts', $lot);
        $save->save($lot, $request->user(), $this->validated($request, $lot));

        return back()->with('success', 'Bank account added. Your team can now share it with customers.');
    }

    public function update(Request $request, Lot $lot, LotBankAccount $bankAccount, SaveBankAccount $save): RedirectResponse
    {
        Gate::authorize('manageBankAccounts', $lot);
        $save->save($lot, $request->user(), $this->validated($request, $lot, $bankAccount), $bankAccount);

        return back()->with('success', 'Bank account saved.');
    }

    public function destroy(Request $request, Lot $lot, LotBankAccount $bankAccount, SaveBankAccount $save): RedirectResponse
    {
        Gate::authorize('manageBankAccounts', $lot);
        $save->remove($lot, $request->user(), $bankAccount);

        return back()->with('success', 'Bank account removed.');
    }

    /** @return array{bank_name: string, account_number: string, account_name: string, is_default: bool} */
    private function validated(Request $request, Lot $lot, ?LotBankAccount $current = null): array
    {
        $request->merge(['account_number' => preg_replace('/\D/', '', (string) $request->input('account_number'))]);

        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:80'],
            'account_number' => ['required', 'regex:'.config('lotlink.bank_account_pattern', '/^\d{10}$/'),
                Rule::unique('lot_bank_accounts')->where('lot_id', $lot->id)->where('bank_name', (string) $request->input('bank_name'))->ignore($current?->id)],
            'account_name' => ['required', 'string', 'min:3', 'max:120'],
            'is_default' => ['boolean'],
        ], [
            'account_number.regex' => 'Enter the 10-digit account number (NUBAN).',
            'account_number.unique' => 'That account is already saved.',
        ]);

        return [
            'bank_name' => (string) $data['bank_name'],
            'account_number' => (string) $data['account_number'],
            'account_name' => (string) $data['account_name'],
            'is_default' => (bool) ($data['is_default'] ?? false),
        ];
    }
}
