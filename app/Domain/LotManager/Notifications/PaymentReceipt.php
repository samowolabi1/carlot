<?php

namespace App\Domain\LotManager\Notifications;

use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\LotManager\Models\OrderPayment;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Support\OrderLinks;
use App\Domain\LotManager\Support\ReceiptPdf;
use App\Domain\Lots\Models\Lot;
use App\Domain\Messaging\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * SendReceipt (TDD M19): the receipt for a payment, with a link to track the order.
 * WhatsApp (SMS fallback) needs the customer's consent; email goes when we have one.
 */
class PaymentReceipt extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly OrderPayment $payment)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        /** @var LotCustomer $notifiable */
        return array_values(array_filter([
            $notifiable->consent_whatsapp ? 'phone' : null,
            filled($notifiable->email) ? 'mail' : null,
        ]));
    }

    public function toPhone(object $notifiable): Message
    {
        [$order, $lot] = $this->context();
        $amount = $order->money($this->payment->amount);
        $balance = $order->balance > 0 ? $order->money($order->balance).' left to pay' : 'fully paid';
        $car = $order->vehicle?->title() ?? 'your car';
        $url = OrderLinks::track($order);

        return new Message(
            'payment_receipt',
            [$notifiable->name ?? 'there', $amount, $lot->name, $car, (string) $this->payment->receipt_no, $balance],
            "{$lot->name}: we received {$amount} for {$car}. Receipt {$this->payment->receipt_no}, {$balance}. Track your order: {$url}",
            Message::suffix($url),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$order, $lot] = $this->context();

        return (new MailMessage)
            ->subject("Receipt {$this->payment->receipt_no} from {$lot->name}")
            ->greeting('Hi '.($notifiable->name ?? 'there').',')
            ->line("{$lot->name} received {$order->money($this->payment->amount)} for ".($order->vehicle?->title() ?? 'your car').'.')
            ->line($order->balance > 0 ? 'Balance to pay: '.$order->money($order->balance).'.' : 'Your order is fully paid.')
            ->action('Track your order on CarYard', OrderLinks::track($order))
            ->attachData(ReceiptPdf::render($this->payment), "receipt-{$this->payment->receipt_no}.pdf", ['mime' => 'application/pdf']);
    }

    /** @return array{SalesOrder, Lot} */
    private function context(): array
    {
        $order = SalesOrder::withoutGlobalScopes()->with(['vehicle.make', 'vehicle.model'])->findOrFail($this->payment->sales_order_id);

        return [$order, Lot::withTrashed()->findOrFail($order->lot_id)];
    }
}
