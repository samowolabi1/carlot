<?php

namespace App\Domain\Admin;

/** The parts of /admin. Each admin role opens some of them (AdminRole::areas()); every resource, page and sensitive action names its area. */
enum AdminArea: string
{
    case Dashboard = 'dashboard';
    case Team = 'team';
    case Settings = 'settings';
    case System = 'system';
    case Approvals = 'approvals';
    case Moderation = 'moderation';
    case Catalogue = 'catalogue';
    case Support = 'support';
    case Communication = 'communication';
    case Billing = 'billing';
    case Loans = 'loans';

    public function label(): string
    {
        return match ($this) {
            self::Dashboard => 'Dashboard and review queue',
            self::Team => 'Admin team',
            self::Settings => 'Settings and prices',
            self::System => 'Audit log and system health',
            self::Approvals => 'Approving lots, lenders and verifications',
            self::Moderation => 'Listings, reports, reviews and adverts',
            self::Catalogue => 'Car makes and models',
            self::Support => 'Support tickets, users and "Log in as"',
            self::Communication => 'Broadcasts to lots',
            self::Billing => 'Payments, plans, coupons, finance rates and the platform figures',
            self::Loans => 'Car loan overview',
        };
    }
}
