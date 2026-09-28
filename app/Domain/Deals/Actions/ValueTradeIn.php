<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Deals\Enums\TradeInStatus;
use App\Domain\Deals\Models\TradeIn;
use App\Domain\Deals\Notifications\DealUpdate;
use App\Domain\Deals\Support\DealLinks;
use App\Domain\Deals\Support\DealTimeline;
use App\Domain\Lots\Models\Lot;
use Illuminate\Validation\ValidationException;

class ValueTradeIn
{
    public function __construct(private readonly DealTimeline $timeline) {}

    /** The lot sends a low–high estimate; the buyer hears on WhatsApp (TDD M12). Minor units. */
    public function run(TradeIn $tradeIn, User $staff, int $low, int $high, ?string $note = null): TradeIn
    {
        if (! in_array($tradeIn->status, [TradeInStatus::Submitted, TradeInStatus::Valued], true)) {
            throw ValidationException::withMessages(['estimate_low' => 'The buyer has already answered this valuation.']);
        }

        if ($low <= 0 || $high < $low) {
            throw ValidationException::withMessages(['estimate_high' => 'The high estimate must be at least the low one.']);
        }

        $tradeIn->forceFill([
            'estimate_low' => $low,
            'estimate_high' => $high,
            'valuation_note' => filled($note) ? trim((string) $note) : null,
            'valued_by' => $staff->id,
            'valued_at' => now(),
            'status' => TradeInStatus::Valued,
        ])->save();

        AuditLog::record('trade_in.valued', $tradeIn, ['low' => $low, 'high' => $high], $staff, $tradeIn->lot_id);

        $lot = Lot::findOrFail($tradeIn->lot_id);
        $title = $tradeIn->loadMissing(['make', 'model'])->title();
        $estimate = (string) $tradeIn->estimate();

        $this->timeline->post($tradeIn->lead, "Trade-in valued: {$title} at {$estimate}".($tradeIn->valuation_note ? " · “{$tradeIn->valuation_note}”" : ''));
        $tradeIn->customer->notify(new DealUpdate('trade_in', "your {$title}", $lot->name, "It's worth {$estimate}. Nothing is binding until they see the car.", DealLinks::buyer()));

        return $tradeIn;
    }
}
