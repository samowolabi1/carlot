<?php

namespace App\Domain\Helpdesk\Enums;

enum TicketStatus: string
{
    /** Waiting on CarYard. */
    case Open = 'open';
    /** CarYard replied and is waiting on the seller. */
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /** How the admin team sees it. */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Needs a reply',
            self::Pending => 'Waiting on the seller',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /** How the seller sees it. */
    public function lotLabel(): string
    {
        return match ($this) {
            self::Open => 'With CarYard',
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
