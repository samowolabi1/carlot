<?php

namespace App\Http\Middleware;

use App\Domain\Admin\Impersonation;
use App\Domain\Legal\Actions\AcceptTerms;
use App\Domain\Legal\LegalDocuments;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * People who haven't accepted the current Terms of Use and Privacy Policy (accounts from before they existed, accounts
 * an admin or a seller made for someone, or after a material change) accept them once before continuing. New sign-ups
 * accept on the sign-in page. CarYard staff are bound by their employment terms instead, and during "Log in as" the
 * admin must never accept on the person's behalf.
 */
class EnsureTermsAccepted
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->isAdmin() && ! Impersonation::active() && ! AcceptTerms::current($user)) {
            return $request->expectsJson() || $request->is('api/*')
                ? response()->json(['message' => 'Accept the current Terms of Use and Privacy Policy to continue.', 'code' => 'terms_not_accepted', 'required_version' => LegalDocuments::userVersion()], 403)
                : redirect()->guest(route('legal.accept'));
        }

        return $next($request);
    }
}
