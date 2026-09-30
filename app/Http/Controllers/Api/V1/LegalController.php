<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Admin\Impersonation;
use App\Domain\Legal\Actions\AcceptTerms;
use App\Domain\Legal\LegalDocuments;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The legal documents for the app (titles, versions and links) and accepting the current Terms and Privacy Policy. */
class LegalController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => collect(LegalDocuments::TITLES)->map(fn (string $title, string $key) => [
            'key' => $key,
            'title' => $title,
            'version' => LegalDocuments::version($key),
            'url' => route('legal.show', $key),
        ])->values(), 'required_version' => LegalDocuments::userVersion()]);
    }

    public function accept(Request $request, AcceptTerms $accept): JsonResponse
    {
        abort_if(Impersonation::active(), 403);
        $request->validate(['agree' => ['accepted']], ['agree.accepted' => 'Agree to the Terms of Use and Privacy Policy to continue.']);
        $accept->run($request->user());

        return response()->json(['data' => ApiPresenter::user($request->user())]);
    }
}
