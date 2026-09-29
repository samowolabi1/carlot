<?php

namespace App\Domain\Trust\Enums;

enum VerificationStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Being checked',
            self::Approved => 'Verified',
            self::Rejected => 'Not approved',
        };
    }
}
