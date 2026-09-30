<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Notifications\FinanceUpdate;
use App\Domain\Finance\Support\FinanceLeadNotice;

/** A status the partner sent later (signed webhook). */
class UpdateFinanceApplication
{
    public function __construct(private readonly FinanceLeadNotice $notice) {}

    public function run(FinanceApplication $application, string $status, ?string $message = null, ?int $approvedAmount = null): FinanceApplication
    {
        $changed = $application->status !== $status;
        $application->update([
            'status' => $status,
            'partner_message' => $message !== null ? mb_substr($message, 0, 255) : $application->partner_message,
            'approved_amount' => $approvedAmount ?? $application->approved_amount,
        ]);

        if ($changed && in_array($status, ['pre_approved', 'declined'], true)) {
            $application->user->notify(new FinanceUpdate($application));
        }
        // Declines stay between the buyer and the partner.
        if ($changed && $status === 'pre_approved') {
            $this->notice->preApproved($application);
        }

        return $application;
    }
}
