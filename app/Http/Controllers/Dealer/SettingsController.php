<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Lots\Actions\SaveLotHours;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dealer\LotBrandingRequest;
use App\Http\Requests\Dealer\LotHoursRequest;
use App\Http\Requests\Dealer\LotLocationRequest;
use App\Http\Requests\Dealer\LotProfileRequest;
use App\Http\Resources\LotSettingsResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function show(Lot $lot): Response
    {
        Gate::authorize('update', $lot);

        $lot->load('hours');

        return Inertia::render('Dealer/Settings', [
            'lot' => new LotSettingsResource($lot),
        ]);
    }

    public function updateProfile(LotProfileRequest $request, Lot $lot): RedirectResponse
    {
        $lot->update($request->validated());

        return $this->done($request, $lot, 'business');
    }

    public function updateBranding(LotBrandingRequest $request, Lot $lot): RedirectResponse
    {
        $disk = Storage::disk(config('lotlink.media_disk'));

        foreach (['logo', 'cover'] as $field) {
            if ($request->hasFile($field)) {
                $old = $lot->{"{$field}_path"};
                $lot->{"{$field}_path"} = $request->file($field)->store("lots/{$lot->ulid}", ['disk' => config('lotlink.media_disk'), 'visibility' => 'public']);

                if ($old) {
                    $disk->delete($old);
                }
            }
        }

        $lot->brand_color = $request->validated('brand_color') ?? $lot->brand_color;
        $lot->save();

        return $this->done($request, $lot, 'branding');
    }

    public function updateLocation(LotLocationRequest $request, Lot $lot): RedirectResponse
    {
        $lot->update($request->validated());

        return $this->done($request, $lot, 'location');
    }

    public function updateHours(LotHoursRequest $request, Lot $lot, SaveLotHours $saveLotHours): RedirectResponse
    {
        $saveLotHours->run($lot, $request->validated());

        return $this->done($request, $lot, 'hours');
    }

    /** Settings forms are shared with the onboarding wizard, which moves on to the next step. */
    private function done(Request $request, Lot $lot, string $step): RedirectResponse
    {
        if ($request->boolean('onboarding') && $next = OnboardingController::nextStep($step)) {
            return redirect()->route('dealer.onboarding.show', [$lot, $next]);
        }

        return back()->with('success', 'Changes saved.');
    }
}
