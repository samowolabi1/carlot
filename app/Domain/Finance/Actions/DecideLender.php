<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminCounters;
use App\Domain\Audit\AuditLog;
use App\Domain\Finance\Enums\LenderStatus;
use App\Domain\Finance\Models\Lender;
use App\Domain\Finance\Notifications\LenderAlert;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * An admin approves, rejects, suspends or reactivates a lender. Only active lenders are offered to buyers and can
 * work applications; a suspended lender keeps its history but gets no new applications.
 */
class DecideLender
{
    public const DECISIONS = ['approve', 'reject', 'suspend', 'reactivate'];

    public function run(Lender $lender, string $decision, User $admin, ?string $note = null): Lender
    {
        [$from, $to] = match ($decision) {
            'approve' => [[LenderStatus::Pending, LenderStatus::Rejected], LenderStatus::Active],
            'reject' => [[LenderStatus::Pending], LenderStatus::Rejected],
            'suspend' => [[LenderStatus::Active], LenderStatus::Suspended],
            'reactivate' => [[LenderStatus::Suspended], LenderStatus::Active],
            default => throw ValidationException::withMessages(['decision' => 'Unknown decision.']),
        };
        if (! in_array($lender->status, $from, true)) {
            throw ValidationException::withMessages(['decision' => "{$lender->name} is {$lender->status->label()}."]);
        }
        $note = filled($note) ? mb_substr(trim((string) $note), 0, 500) : null;
        if (in_array($decision, ['reject', 'suspend'], true) && $note === null) {
            throw ValidationException::withMessages(['note' => 'Say why, so the lender knows what to fix.']);
        }

        $lender->update(['status' => $to, 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_note' => $note]);
        AuditLog::record("lender.{$decision}", $lender, ['note' => $note], $admin);
        AdminCounters::forget();

        $text = match ($decision) {
            'approve' => "{$lender->name} is approved on LotLink. Buyers can now apply to you for car loans.",
            'reactivate' => "{$lender->name} is active on LotLink again.",
            'reject' => "{$lender->name} wasn't approved on LotLink: {$note}",
            'suspend' => "{$lender->name} is paused on LotLink and gets no new applications: {$note}",
        };
        Notification::send($lender->members()->get(), new LenderAlert($text, route('lender.home')));

        return $lender;
    }
}
