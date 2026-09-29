<?php

namespace App\Filament\Resources\CouponResource\Pages;

use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Models\Coupon;
use App\Filament\Resources\CouponResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCoupon extends CreateRecord
{
    protected static string $resource = CouponResource::class;

    protected function afterCreate(): void
    {
        /** @var Coupon $coupon */
        $coupon = $this->record;
        AuditLog::record('admin.coupon_created', $coupon, $coupon->only(['code', 'plan_id', 'trial_days', 'max_redemptions', 'expires_at', 'active']));
    }
}
