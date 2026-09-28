<?php

namespace App\Domain\Deals\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Deals\Enums\TradeInStatus;
use App\Domain\Deals\Models\TradeIn;
use App\Domain\Deals\Notifications\DealAlert;
use App\Domain\Deals\Support\DealLinks;
use App\Domain\Deals\Support\DealTimeline;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Name;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class AnswerTradeIn
{
    public function __construct(private readonly DealTimeline $timeline) {}

    /** The buyer says they'll use the valuation, or not. The lot can then add it to the order. */
    public function run(TradeIn $tradeIn, User $customer, bool $accept): TradeIn
    {
        abort_unless($tradeIn->customer_id === $customer->id, 403);

        if ($tradeIn->status !== TradeInStatus::Valued) {
            throw ValidationException::withMessages(['trade_in' => 'There is no valuation to answer.']);
        }

        $tradeIn->forceFill(['status' => $accept ? TradeInStatus::Accepted : TradeInStatus::Declined])->save();

        $lot = Lot::findOrFail($tradeIn->lot_id);
        $who = Name::short($customer->name);
        $title = $tradeIn->loadMissing(['make', 'model'])->title();
        $line = $accept ? "{$who} wants to trade in their {$title} at {$tradeIn->estimate()}" : "{$who} turned down the valuation for their {$title}";

        $this->timeline->post($tradeIn->lead, $line);
        Notification::send($lot->members()->get(), new DealAlert('trade_in', $line.'.', DealLinks::lot($lot, 'trade-ins')));

        return $tradeIn;
    }
}
