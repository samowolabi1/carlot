<?php

namespace App\Domain\LotManager\Notifications;

use App\Domain\LotManager\Models\FollowUpTask;
use App\Domain\Lots\Models\Lot;
use App\Domain\Messaging\Message;
use App\Domain\Support\PhoneNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** To the staff member who met a walk-in: time to call them back. */
class FollowUpDue extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly FollowUpTask $task)
    {
        $this->onQueue('notifications');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['phone'];
    }

    public function toPhone(object $notifiable): Message
    {
        $customer = $this->task->customer;
        $lot = Lot::withTrashed()->findOrFail($this->task->lot_id);
        $who = $customer->name.' ('.PhoneNumber::display($customer->phone).')';
        $url = route('dealer.manager.today', $lot->slug);

        return new Message(
            'follow_up_due',
            [$notifiable->name ?? 'there', $this->task->type->label(), $who, $lot->name],
            "Follow-up due at {$lot->name}: {$this->task->type->label()} {$who}".($this->task->note ? ". Note: {$this->task->note}" : '').". Open: {$url}",
            Message::suffix($url),
        );
    }
}
