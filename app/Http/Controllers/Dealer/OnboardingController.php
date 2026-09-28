<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Lots\Actions\CreateLot;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use App\Http\Requests\Dealer\LotProfileRequest;
use App\Http\Resources\LotSettingsResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    /** Wizard order, from the D1 onboarding design. CAC verification arrives in sprint S12. */
    public const STEPS = ['business', 'branding', 'location', 'hours', 'staff', 'submit'];

    public function create(Request $request): Response
    {
        // /dealer/start?ref=CODE from another lot's invite (TDD M19: lot referrals).
        if ($request->filled('ref')) {
            $request->session()->put('referral_code', strtoupper(substr((string) $request->query('ref'), 0, 12)));
        }

        return Inertia::render('Dealer/Onboarding', [
            'step' => 'business',
            'steps' => self::STEPS,
            'lot' => null,
            'defaults' => ['phone' => $request->user()->phone, 'email' => $request->user()->email],
        ]);
    }

    public function store(LotProfileRequest $request, CreateLot $createLot): RedirectResponse
    {
        $lot = $createLot->run($request->user(), $request->validated(), $request->session()->pull('referral_code'));

        return redirect()->route('dealer.onboarding.show', [$lot, 'branding']);
    }

    public function show(Lot $lot, string $step): Response
    {
        abort_unless(in_array($step, self::STEPS, true), 404);
        Gate::authorize('update', $lot);

        $lot->load(['hours', 'invitations' => fn ($q) => $q->pending()]);

        return Inertia::render('Dealer/Onboarding', [
            'step' => $step,
            'steps' => self::STEPS,
            'lot' => new LotSettingsResource($lot),
            'defaults' => null,
        ]);
    }

    public function submit(Lot $lot): RedirectResponse
    {
        Gate::authorize('submit', $lot);

        if (! $lot->hasLocation()) {
            return redirect()->route('dealer.onboarding.show', [$lot, 'location'])
                ->with('error', 'Pin your lot on the map before you submit.');
        }

        $lot->forceFill(['submitted_at' => $lot->submitted_at ?? now()])->save();

        return redirect()->route('dealer.dashboard', $lot)
            ->with('success', 'Thanks! We will review your lot and get it live shortly.');
    }

    public static function nextStep(string $current): ?string
    {
        $index = array_search($current, self::STEPS, true);

        return $index === false ? null : (self::STEPS[$index + 1] ?? null);
    }
}
