<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\Marketplace\Models\SavedSearch;
use Illuminate\Support\Facades\DB;

/**
 * Removes a deleted account's personal data (TDD Privacy). The row stays so bookings, reviews
 * and chats keep their shape, shown as "Deleted user". A lot's own customer-book entry is the
 * lot's record (it is the data controller), so only the link to the account is removed.
 */
class AnonymiseAccount
{
    public function run(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->favourites()->detach();
            $user->followedLots()->detach();
            $user->budget()->delete();
            $user->notifications()->delete();
            $user->tokens()->delete();
            $user->pushSubscriptions()->delete();
            SavedSearch::where('user_id', $user->id)->delete();
            FinanceApplication::where('user_id', $user->id)->get()->each(fn (FinanceApplication $a) => $a->forceFill(['applicant' => []])->save());
            LotCustomer::withoutGlobalScopes()->where('user_id', $user->id)->update(['user_id' => null]);
            DB::table('sessions')->where('user_id', $user->id)->delete();

            $user->forceFill([
                'name' => 'Deleted user',
                'phone' => 'deleted-'.$user->id, // fits the 20-character column and stays unique
                'email' => null,
                'password' => null,
                'notification_preferences' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'inspector_since' => null,
                'inspector_company' => null,
                'anonymised_at' => now(),
            ])->saveQuietly();
        });
    }
}
