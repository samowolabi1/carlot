<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Lots\Domains\CustomDomains;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** Custom domain for the mini-site (TDD M6, Enterprise). */
class DomainController extends Controller
{
    public function store(Request $request, Lot $lot, CustomDomains $domains): RedirectResponse
    {
        $this->authorizeDomain($lot);
        $domains->set($lot, $request->user(), (string) $request->validate(['domain' => ['required', 'string', 'max:190']])['domain']);

        return back()->with('success', 'Domain saved. Add the two DNS records below, then press Check.');
    }

    public function verify(Lot $lot, CustomDomains $domains): RedirectResponse
    {
        $this->authorizeDomain($lot);

        return $domains->verify($lot)
            ? back()->with('success', "Verified. {$lot->custom_domain} now opens your mini-site (the secure certificate can take a few minutes).")
            : back()->with('error', "We can't see the TXT record yet. DNS changes can take up to an hour; try again later.");
    }

    public function destroy(Request $request, Lot $lot, CustomDomains $domains): RedirectResponse
    {
        $this->authorizeDomain($lot);
        $domains->remove($lot, $request->user());

        return back()->with('success', 'Custom domain removed. Your LotLink link keeps working.');
    }

    /** Caddy on-demand TLS "ask" endpoint: certificates only for LotLink and verified lot domains. */
    public function allowed(Request $request): Response
    {
        $domain = CustomDomains::normalise((string) $request->query('domain'));
        $ok = $domain === CustomDomains::appHost() || CustomDomains::lotFor($domain) !== null;

        return response($ok ? 'ok' : 'unknown', $ok ? 200 : 404);
    }

    private function authorizeDomain(Lot $lot): void
    {
        Gate::authorize('manageBilling', $lot);
        abort_unless($lot->planAllows('custom_domain'), 403, 'Custom domains are on the Enterprise plan.');
    }
}
