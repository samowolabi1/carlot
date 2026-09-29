<?php

namespace App\Domain\Advertising\Enums;

enum AdStatus: string
{
    case Draft = 'draft';          // created, not paid yet
    case InReview = 'in_review';   // paid; LotLink checks it before it runs
    case Approved = 'approved';    // scheduled or running (see AdCampaign::state())
    case Rejected = 'rejected';    // refunded
    case Removed = 'removed';      // taken down by LotLink after approval

    /** @return list<self> statuses that hold a slot */
    public static function holding(): array
    {
        return [self::InReview, self::Approved];
    }
}
