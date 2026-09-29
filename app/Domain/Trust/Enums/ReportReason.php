<?php

namespace App\Domain\Trust\Enums;

enum ReportReason: string
{
    case Scam = 'scam';
    case Misleading = 'misleading';
    case Sold = 'sold';
    case Duplicate = 'duplicate';
    case Offensive = 'offensive';
    case Spam = 'spam';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Scam => 'Looks like a scam',
            self::Misleading => 'Wrong or misleading details',
            self::Sold => 'Car is no longer for sale',
            self::Duplicate => 'Listed more than once',
            self::Offensive => 'Rude or offensive',
            self::Spam => 'Spam',
            self::Other => 'Something else',
        };
    }

    /** Reasons offered for each kind of content. @return list<self> */
    public static function for(string $kind): array
    {
        return match ($kind) {
            'vehicle' => [self::Scam, self::Misleading, self::Sold, self::Duplicate, self::Other],
            'lot' => [self::Scam, self::Misleading, self::Offensive, self::Other],
            'review' => [self::Offensive, self::Misleading, self::Spam, self::Other],
            default => [self::Scam, self::Offensive, self::Spam, self::Other],
        };
    }
}
