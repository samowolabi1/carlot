<?php

namespace App\Domain\Finance\Enums;

use App\Domain\Support\HasOptions;

/** Where a car loan application stands. The lender moves it; the buyer can withdraw while it's open. */
enum FinanceStatus: string
{
    use HasOptions;

    case Submitted = 'submitted';
    case Received = 'received';
    case DocumentsRequested = 'documents_requested';
    case PreApproved = 'pre_approved';
    case Approved = 'approved';
    case Disbursed = 'disbursed';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Sent',
            self::Received => 'Being reviewed',
            self::DocumentsRequested => 'Documents needed',
            self::PreApproved => 'Pre-approved',
            self::Approved => 'Approved',
            self::Disbursed => 'Paid to the seller',
            self::Declined => 'Not approved',
            self::Withdrawn => 'Withdrawn',
            self::Failed => "Couldn't send",
        };
    }

    /** Still with the lender: it can move on, be declined or be withdrawn. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Submitted, self::Received, self::DocumentsRequested, self::PreApproved, self::Approved], true);
    }

    /** @return list<self> */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isOpen()));
    }

    /** The statuses a lender's action may move an application from. @return list<self> */
    public static function allowedFrom(string $action): array
    {
        return match ($action) {
            'review' => [self::Submitted],
            'documents' => [self::Submitted, self::Received, self::DocumentsRequested, self::PreApproved],
            'pre_approve' => [self::Submitted, self::Received, self::DocumentsRequested],
            'approve' => [self::Submitted, self::Received, self::DocumentsRequested, self::PreApproved],
            'disburse' => [self::Approved],
            'decline' => self::open(),
            default => [],
        };
    }

    /** Can an application move from this status to $to? Staying put is allowed (a new message, a new amount). */
    public function canMoveTo(self $to): bool
    {
        if ($to === $this) {
            return $this->isOpen() || $this === self::Disbursed;
        }

        return match ($to) {
            self::Received => in_array($this, self::allowedFrom('review'), true),
            self::DocumentsRequested => in_array($this, self::allowedFrom('documents'), true),
            self::PreApproved => in_array($this, self::allowedFrom('pre_approve'), true),
            self::Approved => in_array($this, self::allowedFrom('approve'), true),
            self::Disbursed => in_array($this, self::allowedFrom('disburse'), true),
            self::Declined, self::Withdrawn => $this->isOpen(),
            self::Failed => $this === self::Submitted,
            self::Submitted => false,
        };
    }

    /** Colour of the status chip in the apps (the same names as the Tailwind tones in Vue). */
    public function tone(): string
    {
        return match ($this) {
            self::Submitted, self::Received => 'waiting',
            self::DocumentsRequested => 'action',
            self::PreApproved, self::Approved, self::Disbursed => 'good',
            self::Declined, self::Withdrawn => 'closed',
            self::Failed => 'bad',
        };
    }
}
