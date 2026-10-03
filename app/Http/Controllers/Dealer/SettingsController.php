<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Lots\Actions\SaveLotHours;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotBankAccount;
use App\Domain\Lots\Models\LotClosure;
use App\Domain\Support\Fields;
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
                'accepts_trade_ins' => $lot->accepts_trade_ins,
                'accepts_finance' => $lot->accepts_finance,
                'reservation_deposit' => $lot->reservation_deposit ? intdiv($lot->reservation_deposit, 100) : null,
                'reservation_refundable' => $lot->reservation_refundable,
                'plan' => ['offers' => $lot->planAllows('offers'), 'deposits' => $lot->planAllows('deposits')],
            ],
            'verification' => VerificationController::present($lot, Gate::allows('submit', $lot)),
            'social' => SocialController::present($lot),
            'bank' => [
                'accounts' => $lot->bankAccounts()->get()->map(fn (LotBankAccount $a) => $a->present()),
                'can_edit' => Gate::allows('manageBankAccounts', $lot),
                'banks' => LotBankAccount::BANKS,
                'max' => LotBankAccount::MAX,
            ],
        ]);
    }

    /** Offers and reservations (TDD M12): whether buyers can make offers, and the reservation deposit they pay the seller directly. */
    public function updateDeals(Request $request, Lot $lot): RedirectResponse
    {
        Gate::authorize('update', $lot);

        $request->merge(['reservation_deposit' => Fields::cleanMoney($request->input('reservation_deposit'))]);

        $data = $request->validate([
            'accepts_offers' => ['required', 'boolean'],
            'accepts_trade_ins' => ['sometimes', 'boolean'],
            'accepts_finance' => ['sometimes', 'boolean'],
            'reservation_deposit' => ['nullable', 'integer', 'min:1000', 'max:50000000'],
            'reservation_refundable' => ['required', 'boolean'],
        ], [
            'reservation_deposit.min' => 'A reservation deposit starts at ₦1,000.',
        ]);

        // Buyers pay the seller directly, so reservations need somewhere to pay.
        if (isset($data['reservation_deposit']) && ! $lot->bankAccounts()->exists()) {
            return back()->withErrors(['reservation_deposit' => 'Add your bank details first so buyers know where to send the deposit.']);
        }

        $lot->update([
            'accepts_offers' => $data['accepts_offers'],
            'accepts_trade_ins' => $data['accepts_trade_ins'] ?? $lot->accepts_trade_ins,
            'accepts_finance' => $data['accepts_finance'] ?? $lot->accepts_finance,
            'reservation_deposit' => isset($data['reservation_deposit']) ? Money::fromMajor((int) $data['reservation_deposit']) : null,
            'reservation_refundable' => $data['reservation_refundable'],
            'test_drive_deposit' => null, // CarYard doesn't take test-drive deposits: the seller is paid directly
        ]);

        return back()->with('success', 'Offers and deals settings saved.');
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
