<?php

namespace App\Domain\Helpdesk\Enums;

enum TicketStatus: string
{
    /** Waiting on LotLink. */
    case Open = 'open';
    /** LotLink replied and is waiting on the lot. */
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /** How the admin team sees it. */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Needs a reply',
            self::Pending => 'Waiting on the lot',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /** How the lot sees it. */
    public function lotLabel(): string
    {
        return match ($this) {
            self::Open => 'With LotLink',
            self::Pending => 'Waiting for you',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Open, self::Pending], true);
    }

    /** @return list<self> */
    public static function active(): array
    {
        return [self::Open, self::Pending];
    }
}
