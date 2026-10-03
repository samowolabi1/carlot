<?php

namespace App\Domain\Advertising\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Advertising\Enums\AdCta;
use App\Domain\Advertising\Enums\AdPlacement;
use App\Domain\Advertising\Enums\AdStatus;
use App\Domain\Advertising\Models\AdCampaign;
use App\Domain\Advertising\Support\AdImage;
use App\Domain\Advertising\Support\AdSchedule;
use App\Domain\Billing\Enums\PaymentPurpose;
use App\Domain\Billing\Gateways\PaymentGateway;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Support\BillingEmail;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A seller books an advert and pays CarYard for it (one of the seller's own payments, like its plan
 * and spotlights). Once paid it goes to an admin to check (SubmitAdCampaign); a rejected
 * advert is refunded.
 */
class CreateAdCampaign
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * @param  array{placement: string, days: int, start: string, headline: string, subtext?: string|null, cta: string, vehicle?: string|null, make_id?: int|null, body_type?: string|null, city?: string|null}  $data
     * @return array{campaign: AdCampaign, checkout: string}
     */
    public function run(Lot $lot, User $user, array $data, ?UploadedFile $image = null): array
    {
        if ($lot->status !== LotStatus::Active) {
            throw ValidationException::withMessages(['placement' => 'Adverts can run once CarYard has approved your business.']);
        }

        $placement = AdPlacement::from($data['placement']);
        $days = (int) $data['days'];
        $price = AdSchedule::price($placement, $days) ?? throw ValidationException::withMessages(['days' => 'Choose 7, 14 or 30 days.']);

        $start = AdSchedule::dayStart($data['start']);
        if ($start->lt(AdSchedule::today()) || $start->gt(AdSchedule::today()->addDays(AdCampaign::BOOK_AHEAD_DAYS))) {
            throw ValidationException::withMessages(['start' => 'Pick a start date between today and '.AdCampaign::BOOK_AHEAD_DAYS.' days from now.']);
        }

        $vehicle = filled($data['vehicle'] ?? null)
            ? Vehicle::query()->marketplace()->where('vehicles.lot_id', $lot->id)->where('vehicles.ulid', strtolower((string) $data['vehicle']))->with('cover')->first()
                ?? throw ValidationException::withMessages(['vehicle' => 'Pick one of your cars that is live on CarYard.'])
            : null;

        if ($image === null && $vehicle?->cover === null) {
            throw ValidationException::withMessages(['image' => 'Upload a banner image, or advertise a car so its photo is used.']);
        }

        return DB::transaction(function () use ($lot, $user, $data, $image, $placement, $days, $price, $start, $vehicle) {
            // Slots are counted under a lock so two lots can't book the last one together.
            AdCampaign::withoutGlobalScopes()->where('placement', $placement)->lockForUpdate()->get(['id']);

            if (! AdSchedule::hasRoom($placement, $start, $days)) {
                $next = AdSchedule::nextStart($placement, $start, $days)->setTimezone((string) config('lotlink.timezone'));
                throw ValidationException::withMessages(['start' => "All {$placement->label()} slots are taken then. The next free start is {$next->format('D j M')}."]);
            }

            $campaign = AdCampaign::withoutGlobalScopes()->create([
                'lot_id' => $lot->id,
                'placement' => $placement,
                'status' => AdStatus::Draft,
                'headline' => trim($data['headline']),
                'subtext' => filled($data['subtext'] ?? null) ? trim((string) $data['subtext']) : null,
                'cta' => AdCta::from($data['cta']),
                'vehicle_id' => $vehicle?->id,
                'targeting' => $placement->targetable() ? array_filter([
                    'make_id' => filled($data['make_id'] ?? null) ? (int) $data['make_id'] : null,
                    'body_type' => $data['body_type'] ?? null,
                    'city' => filled($data['city'] ?? null) ? trim((string) $data['city']) : null,
                ]) ?: null : null,
                'days' => $days,
                'requested_start' => $start->copy()->setTimezone((string) config('lotlink.timezone'))->toDateString(),
                'price' => $price,
                'currency' => (string) config('lotlink.currency'),
                'created_by' => $user->id,
            ]);

            if ($image !== null) {
                $campaign->forceFill(['image_path' => AdImage::store($image, $placement, $campaign->ulid)])->save();
            }

            $payment = Payment::create([
                'payable_type' => $campaign->getMorphClass(),
                'payable_id' => $campaign->id,
                'user_id' => $user->id,
                'lot_id' => $lot->id,
                'purpose' => PaymentPurpose::Advert,
                'description' => "{$placement->label()} · {$days} days",
                'amount' => $price,
                'currency' => $campaign->currency,
                'provider' => $this->gateway->name(),
                'reference' => Payment::newReference('ad'),
            ]);
            $campaign->forceFill(['payment_id' => $payment->id])->save();

            return ['campaign' => $campaign, 'checkout' => $this->gateway->checkout($payment, BillingEmail::for($lot), route('dealer.billing.callback', $lot))];
        });
    }
}
