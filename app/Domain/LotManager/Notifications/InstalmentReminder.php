<?php

namespace App\Domain\LotManager\Notifications;

use App\Domain\LotManager\Models\Instalment;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Support\OrderLinks;
use App\Domain\Lots\Models\Lot;
use App\Domain\Messaging\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * To a customer who agreed to WhatsApp: an instalment is due in 3 days, or today (TDD M19).
 * Template instalment_reminder: [name, amount, car, lot, due date].
 */
class InstalmentReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Instalment $instalment, public readonly bool $today)
    {
        $this->onQueue('notifications');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        /** @var LotCustomer $notifiable */
        return $notifiable->consent_whatsapp ? ['phone'] : [];
    }

    public function toPhone(object $notifiable): Message
    {
        $order = SalesOrder::withoutGlobalScopes()->with(['vehicle.make', 'vehicle.model'])->findOrFail($this->instalment->sales_order_id);
        $lot = Lot::withTrashed()->findOrFail($order->lot_id);
        $amount = $order->money($this->instalment->remaining());
        $car = $order->vehicle?->title() ?? 'your car';
        $when = $this->today ? 'today' : $this->instalment->due_date->format('D j M');
        $url = OrderLinks::track($order);

        return new Message(
            'instalment_reminder',
            [$notifiable->name ?? 'there', $amount, $car, $lot->name, $when],
            "{$lot->name}: your instalment of {$amount} for {$car} is due {$when}. Track your order: {$url}",
            Message::suffix($url),
        );
    }
}
