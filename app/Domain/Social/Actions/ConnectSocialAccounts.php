<?php

namespace App\Domain\Social\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Lots\Models\Lot;
use App\Domain\Social\Models\SocialAccount;
use Illuminate\Validation\ValidationException;

/**
 * Saves the Page (and its Instagram account) a seller connected. A seller has one of each;
 * connecting again replaces them. When Meta returns several Pages, the first one is used.
 */
class ConnectSocialAccounts
{
    /**
     * @param  list<array{provider: string, page_id: string, name: string, token: string, expires_at: ?\DateTimeInterface}>  $accounts
     * @return list<SocialAccount>
     */
    public function run(Lot $lot, User $user, array $accounts): array
    {
        $saved = [];

        foreach (array_keys(SocialAccount::PROVIDERS) as $provider) {
            $account = collect($accounts)->firstWhere('provider', $provider);

            if ($account === null) {
                continue;
            }

            $saved[] = SocialAccount::withoutGlobalScopes()->updateOrCreate(
                ['lot_id' => $lot->id, 'provider' => $provider],
                ['page_id' => $account['page_id'], 'name' => mb_substr($account['name'], 0, 160), 'token' => $account['token'], 'expires_at' => $account['expires_at'],
                    'connected_by' => $user->id, 'last_error' => null, 'last_error_at' => null],
            );
        }

        if ($saved === []) {
            throw ValidationException::withMessages(['social' => "We couldn't find a Facebook Page you manage. Create a Page (and link Instagram to it in Meta Business Suite), then try again."]);
        }

        AuditLog::record('lot.social_connected', $lot, ['accounts' => collect($saved)->map(fn (SocialAccount $a) => "{$a->provider}: {$a->name}")->all()], $user, $lot->id);

        return $saved;
    }
}
