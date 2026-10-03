<?php

namespace App\Http\Middleware;

use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Models\FinanceMessage;
use App\Domain\Finance\Models\Lender;
use App\Domain\Legal\Actions\AcceptTerms;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * For /lender/{lender}/... routes: only the lender's team gets in (403 for anyone else, CarYard admins included:
 * they see applications in /admin). Shares the lender with the page as `currentLender`.
 */
class SetCurrentLender
{
    public function handle(Request $request, Closure $next): Response
    {
        $lender = $request->route('lender');
        abort_unless($lender instanceof Lender, 404);

        $role = $lender->roleOf($request->user());
        abort_if($role === null, 403);

        // Until one of the lender's admins accepts the current Lender Terms, only the dashboard (where they accept), team and
        // settings open; applications wait.
        $termsOk = AcceptTerms::lenderCurrent($lender);
        if (! $termsOk && ! in_array($request->route()?->getName(), ['lender.dashboard', 'lender.terms', 'lender.team', 'lender.team.store', 'lender.team.destroy', 'lender.settings', 'lender.settings.update'], true)) {
            return redirect()->route('lender.dashboard', $lender)->with('error', 'Your admin needs to accept the Lender Terms before you can work applications.');
        }

        $request->attributes->set('currentLender', fn () => [
            'terms_ok' => $termsOk,
            'slug' => $lender->slug,
            'name' => $lender->name,
            'initials' => Str::upper(collect(explode(' ', $lender->name))->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')),
            'status' => $lender->status->value,
            'status_label' => $lender->status->label(),
            'role' => $role->value,
            'new_badge' => $lender->applications()->where('status', FinanceStatus::Submitted)->count(),
            'unread_badge' => $lender->applications()->whereIn('status', FinanceStatus::open())
                ->whereExists(fn ($q) => $q->from('finance_messages')->whereColumn('finance_messages.finance_application_id', 'finance_applications.id')
                    ->where('finance_messages.side', FinanceMessage::BUYER)
                    ->where(fn ($q) => $q->whereNull('finance_applications.lender_read_at')->orWhereColumn('finance_messages.created_at', '>', 'finance_applications.lender_read_at')))
                ->count(),
        ]);

        return $next($request);
    }
}
