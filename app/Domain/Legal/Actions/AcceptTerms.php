<?php

namespace App\Domain\Legal\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Finance\Models\Lender;
use App\Domain\Legal\LegalDocuments;
use App\Domain\Legal\Models\LegalAcceptance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records that someone accepted the current Terms of Use and Privacy Policy (run(): on sign-up from the sign-in page,
 * which says so next to the button, or on the "we've updated our terms" page), or, for a lender, the Lender Terms
 * (forLender()). Each acceptance is kept with its version, time, IP and browser as evidence.
 */
class AcceptTerms
{
    public function run(User $user): void
    {
        DB::transaction(function () use ($user) {
            foreach (LegalDocuments::FOR_EVERYONE as $doc) {
                $this->record($user, $doc);
            }
            $user->forceFill(['terms_version' => LegalDocuments::userVersion(), 'terms_accepted_at' => now()])->save();
        });
    }

    public function forLender(Lender $lender, User $by): void
    {
        DB::transaction(function () use ($lender, $by) {
            $this->record($by, 'lender-terms', $lender);
            $lender->forceFill(['terms_version' => LegalDocuments::version('lender-terms'), 'terms_accepted_at' => now()])->save();
            AuditLog::record('lender.terms_accepted', $lender, ['version' => LegalDocuments::version('lender-terms')], $by);
        });
    }

    public static function current(User $user): bool
    {
        return $user->terms_version === LegalDocuments::userVersion();
    }

    public static function lenderCurrent(Lender $lender): bool
    {
        return $lender->terms_version === LegalDocuments::version('lender-terms');
    }

    private function record(User $user, string $doc, ?Lender $lender = null): void
    {
        $request = app()->runningInConsole() ? null : request();
        LegalAcceptance::create([
            'user_id' => $user->id,
            'lender_id' => $lender?->id,
            'document' => $doc,
            'version' => LegalDocuments::version($doc),
            'ip' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
            'accepted_at' => now(),
        ]);
    }
}
