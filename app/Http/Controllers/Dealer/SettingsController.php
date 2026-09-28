<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Lots\Actions\SaveLotHours;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotClosure;
use App\Domain\Support\Money;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dealer\LotBookingRulesRequest;
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
            'booking' => [
                'auto_confirm' => $lot->booking_auto_confirm,
                'min_notice_minutes' => $lot->booking_min_notice_minutes,
                'closures' => LotClosure::query()->where('date', '>=', now($lot->timezone)->toDateString())->orderBy('date')->get()
                    ->map(fn (LotClosure $c) => ['id' => $c->id, 'date' => $c->date->toDateString(), 'label' => $c->date->format('D j M Y'), 'reason' => $c->reason]),
            ],
            'deals' => [
                'accepts_offers' => $lot->accepts_offers,
                'reservation_deposit' => $lot->reservation_deposit ? intdiv($lot->reservation_deposit, 100) : null,
                'reservation_refundable' => $lot->reservation_refundable,
                'test_drive_deposit' => $lot->test_drive_deposit ? intdiv($lot->test_drive_deposit, 100) : null,
                'plan' => ['offers' => $lot->planAllows('offers'), 'deposits' => $lot->planAllows('deposits')],
            ],
        ]);
    }

    /** Offers and deposits (TDD M12): whether buyers can make offers, and the deposit amounts. */
    public function updateDeals(Request $request, Lot $lot): RedirectResponse
    {
        Gate::authorize('update', $lot);

        foreach (['reservation_deposit', 'test_drive_deposit'] as $key) {
            $request->merge([$key => preg_replace('/[^\d]/', '', (string) $request->input($key)) ?: null]);
        }

        $data = $request->validate([
            'accepts_offers' => ['required', 'boolean'],
            'reservation_deposit' => ['nullable', 'integer', 'min:1000', 'max:50000000'],
            'reservation_refundable' => ['required', 'boolean'],
            'test_drive_deposit' => ['nullable', 'integer', 'min:500', 'max:5000000'],
        ], [
            'reservation_deposit.min' => 'A reservation deposit starts at ₦1,000.',
            'test_drive_deposit.min' => 'A test-drive deposit starts at ₦500.',
        ]);

        $lot->update([
            'accepts_offers' => $data['accepts_offers'],
            'reservation_deposit' => isset($data['reservation_deposit']) ? Money::fromMajor((int) $data['reservation_deposit']) : null,
            'reservation_refundable' => $data['reservation_refundable'],
            'test_drive_deposit' => isset($data['test_drive_deposit']) ? Money::fromMajor((int) $data['test_drive_deposit']) : null,
        ]);

        return back()->with('success', 'Offer and deposit settings saved.');
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

    public function updateBooking(LotBookingRulesRequest $request, Lot $lot): RedirectResponse
    {
        $lot->update($request->validated());

        return back()->with('success', 'Booking rules saved.');
    }

    public function storeClosure(Request $request, Lot $lot): RedirectResponse
    {
        Gate::authorize('update', $lot);

        $data = $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        LotClosure::query()->updateOrCreate(['lot_id' => $lot->id, 'date' => $data['date']], ['reason' => $data['reason'] ?? null]);

        return back()->with('success', 'Closure added. Buyers can\'t book that day.');
    }

    public function destroyClosure(Lot $lot, LotClosure $closure): RedirectResponse
    {
        Gate::authorize('update', $lot);
        $closure->delete();

        return back()->with('success', 'Closure removed.');
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
