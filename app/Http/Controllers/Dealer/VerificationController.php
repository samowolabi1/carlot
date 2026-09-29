<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Lots\Models\Lot;
use App\Domain\Trust\Actions\SubmitLotVerification;
use App\Domain\Trust\Models\LotVerification;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dealer\LotVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** "Verify with CAC" (onboarding step 6 and Settings). */
class VerificationController extends Controller
{
    public function store(LotVerificationRequest $request, Lot $lot, SubmitLotVerification $submit): RedirectResponse
    {
        $submit->run($lot, $request->user(), $request->validated('cac_number'), $request->file('certificate'), $request->file('frontage'));

        return back()->with('success', 'Thanks. We check documents within 2 working days and will let you know.');
    }

    /** @return array<string, mixed> the verification card's props */
    public static function present(Lot $lot, bool $canSubmit): array
    {
        $latest = $lot->latestVerification;

        return [
            'verified' => $lot->isVerified(),
            'verified_on' => $lot->verified_at?->timezone($lot->timezone)->format('j M Y'),
            'current' => $latest?->toDealerArray($lot->timezone),
            'can_submit' => $canSubmit && ! $lot->isVerified(),
        ];
    }

    /** Private files behind a short-lived signed link, made only for the owner's pages and admins. */
    public function file(LotVerification $verification, string $file): StreamedResponse
    {
        $path = $verification->filePath($file) ?? abort(404);
        $disk = Storage::disk(LotVerification::DISK);
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, basename($path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
