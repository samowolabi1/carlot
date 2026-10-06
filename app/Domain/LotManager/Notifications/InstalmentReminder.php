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

    /** The instalment by id: changing the plan replaces the instalments while a reminder may be queued. */
    public readonly int $instalmentId;

    private ?Instalment $current = null;

    public function __construct(Instalment $instalment, public readonly bool $today)
    {
        $this->instalmentId = (int) $instalment->getKey();
        $this->onQueue('notifications');
    }

    /** Not for an instalment replaced by a new plan, or paid off since the reminder was queued. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $this->current = Instalment::withoutGlobalScopes()->find($this->instalmentId);

        return $this->current !== null && $this->current->remaining() > 0;
    }

    private function instalment(): Instalment
    {
        return $this->current ??= Instalment::withoutGlobalScopes()->findOrFail($this->instalmentId);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        /** @var LotCustomer $notifiable */
        return $notifiable->consent_whatsapp ? ['phone'] : [];
    }

    public function toPhone(object $notifiable): Message
    {
        $order = SalesOrder::withoutGlobalScopes()->with(['vehicle.make', 'vehicle.model'])->findOrFail($this->instalment()->sales_order_id);
        $lot = Lot::withTrashed()->findOrFail($order->lot_id);
        $amount = $order->money($this->instalment()->remaining());
        $car = $order->vehicle?->title() ?? 'your car';
        $when = $this->today ? 'today' : $this->instalment()->due_date->format('D j M');
        $url = OrderLinks::track($order);

        return new Message(
            'instalment_reminder',
            [$notifiable->name ?? 'there', $amount, $car, $lot->name, $when],
            "{$lot->name}: your instalment of {$amount} for {$car} is due {$when}. Track your order: {$url}",
            Message::suffix($url),
        );
    }
}
