<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Lots\Actions\RewardReferral;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotReferral;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** GET /referrals (TDD M19): the lot's referral code, invite link and who joined with it. */
class ReferralController extends Controller
{
    public function __invoke(Lot $lot): Response
    {
        Gate::authorize('manageBilling', $lot);

        $link = route('dealer.onboarding.start', ['ref' => $lot->referralCode()]);
        $message = "I use LotLink to list my cars, take bookings and track sales at {$lot->name}. Join with my link: {$link}";

        return Inertia::render('Dealer/Referrals', [
            'code' => $lot->referralCode(),
            'link' => $link,
            'whatsapp' => 'https://wa.me/?text='.rawurlencode($message),
            'months' => RewardReferral::MONTHS,
            'referrals' => LotReferral::where('referrer_lot_id', $lot->id)->with('referred')->latest('id')->get()->map(fn (LotReferral $r) => [
                'lot' => $r->referred->name ?? 'A lot',
                'joined' => $r->created_at?->copy()->setTimezone($lot->timezone)->format('j M Y'),
                'status' => match ($r->status) {
                    'rewarded' => "Chose a plan · you got {$r->reward_months} free month".($r->reward_months === 1 ? '' : 's'),
                    default => 'Signed up · free month when they choose a plan',
                },
                'rewarded' => $r->status === 'rewarded',
            ]),
        ]);
    }
}
