<?php

namespace App\Http\Controllers;

use App\Domain\Admin\Impersonation;
use App\Domain\Legal\Actions\AcceptTerms;
use App\Domain\Legal\LegalDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The legal pages (/terms, /privacy, /lender-terms, /security), accepting updated terms, and security.txt. */
class LegalController extends Controller
{
    public function show(string $document): Response
    {
        abort_unless(LegalDocuments::exists($document), 404);
        $doc = LegalDocuments::render($document);

        return Inertia::render('Legal/Show', [
            'document' => $document,
            ...$doc,
            'others' => collect(LegalDocuments::TITLES)->except($document)->map(fn (string $title, string $key) => ['key' => $key, 'title' => $title])->values(),
        ])->withViewData(['meta' => [
            'title' => "{$doc['title']} — LotLink",
            'description' => "LotLink's {$doc['title']}, effective {$doc['effective']}.",
        ]]);
    }

    public function accept(Request $request): Response|RedirectResponse
    {
        if (AcceptTerms::current($request->user())) {
            return redirect()->intended(route('home'));
        }

        return Inertia::render('Legal/Accept', [
            'updated' => $request->user()->terms_version !== null,
            'effective' => LegalDocuments::render('terms')['effective'],
            'impersonating' => Impersonation::active(),
        ])->withViewData(['meta' => ['title' => 'Terms and privacy', 'robots' => 'noindex']]);
    }

    public function store(Request $request, AcceptTerms $accept): RedirectResponse
    {
        abort_if(Impersonation::active(), 403, 'Only the account holder can accept the terms.');
        $request->validate(['agree' => ['accepted']], ['agree.accepted' => 'Tick the box to agree to the Terms of Use and Privacy Policy.']);

        $accept->run($request->user());

        return redirect()->intended(route('home'));
    }

    /** RFC 9116: where to report vulnerabilities. */
    public function securityTxt(): HttpResponse
    {
        $body = implode("\n", [
            'Contact: mailto:'.config('lotlink.legal.security_email'),
            'Expires: '.now()->addYear()->startOfDay()->toIso8601ZuluString(),
            'Preferred-Languages: en',
            'Policy: '.route('legal.show', 'security').'#5-reporting-a-security-problem',
            'Canonical: '.url('/.well-known/security.txt'),
        ])."\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
