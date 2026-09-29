<?php

namespace App\Console\Commands;

use App\Domain\Accounts\Actions\AnonymiseAccount;
use App\Domain\Accounts\Actions\DeleteAccount;
use App\Domain\Accounts\Models\User;
use Illuminate\Console\Command;

/** Daily: anonymise accounts deleted more than 30 days ago (TDD Privacy). */
class AnonymiseDeletedAccounts extends Command
{
    protected $signature = 'accounts:anonymise';

    protected $description = 'Remove personal data from accounts deleted over 30 days ago';

    public function handle(AnonymiseAccount $anonymise): int
    {
        $count = 0;
        User::onlyTrashed()->whereNull('anonymised_at')->where('deletion_requested_at', '<=', now()->subDays(DeleteAccount::GRACE_DAYS))
            ->each(function (User $user) use ($anonymise, &$count): void {
                $anonymise->run($user);
                $count++;
            });

        $this->info("Anonymised {$count} accounts.");

        return self::SUCCESS;
    }
}
