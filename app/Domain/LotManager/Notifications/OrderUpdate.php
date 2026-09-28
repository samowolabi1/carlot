<?php

namespace App\Domain\LotManager\Notifications;

use App\Domain\LotManager\Enums\OrderStatus;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Support\OrderLinks;
use App\Domain\Lots\Models\Lot;
use App\Domain\Messaging\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** To a customer who agreed to WhatsApp: their papers are ready, or the car is handed over. */
class OrderUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly SalesOrder $order)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['phone'];
    }

    public function toPhone(object $notifiable): ?Message
    {
        $order = SalesOrder::withoutGlobalScopes()->with(['vehicle.make', 'vehicle.model'])->findOrFail($this->order->id);
        $lot = Lot::withTrashed()->findOrFail($order->lot_id);
        $car = $order->vehicle?->title() ?? 'your car';
        $url = OrderLinks::track($order);

        $update = match ($order->status) {
            OrderStatus::PapersReady => "the papers for your {$car} are ready",
            OrderStatus::Delivered => "your {$car} has been handed over. Enjoy the drive",
            default => null,
        };

        if ($update === null) {
            return null;
        }

        return new Message(
            'order_update',
            [$notifiable->name ?? 'there', $car, $lot->name, $update],
            "{$lot->name}: {$update}. Order {$order->order_no}: {$url}",
            Message::suffix($url),
        );
    }
}
