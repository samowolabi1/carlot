<?php

namespace App\Domain\Admin;

use App\Domain\Support\HasOptions;

/** What a LotLink staff member can do in /admin. Owners run the team; the others see only their part of the panel. */
enum AdminRole: string
{
    use HasOptions;

    case Owner = 'owner';
    case Operations = 'operations';
    case Support = 'support';
    case Finance = 'finance';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Operations => 'Operations',
            self::Support => 'Support',
            self::Finance => 'Finance',
            self::Viewer => 'Viewer',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Everything, including the admin team, settings and prices.',
            self::Operations => 'Approves lots, lenders and verifications; moderates listings, reports, reviews and adverts; keeps the car catalogue.',
            self::Support => 'Answers support tickets, looks up users and lots, uses "Log in as" and sends broadcasts.',
            self::Finance => 'Payments, plans, coupons, finance rates and the car loan overview.',
            self::Viewer => 'Read-only dashboard and review queue.',
        };
    }

    /** @return list<AdminArea> */
    public function areas(): array
    {
        return match ($this) {
            self::Owner => AdminArea::cases(),
            self::Operations => [AdminArea::Dashboard, AdminArea::Approvals, AdminArea::Moderation, AdminArea::Catalogue],
            self::Support => [AdminArea::Dashboard, AdminArea::Support, AdminArea::Communication],
            self::Finance => [AdminArea::Dashboard, AdminArea::Billing, AdminArea::Loans],
            self::Viewer => [AdminArea::Dashboard],
        };
    }

    public function allows(AdminArea $area): bool
    {
        return in_array($area, $this->areas(), true);
    }
}
