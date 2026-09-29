<?php

namespace App\Filament\Resources\CouponResource\Pages;

use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Models\Coupon;
use App\Filament\Resources\CouponResource;
use Filament\Resources\Pages\EditRecord;

class EditCoupon extends EditRecord
{
    protected static string $resource = CouponResource::class;

    /** @var array<string, mixed> */
    private array $before = [];

    protected function beforeSave(): void
    {
        /** @var Coupon $coupon */
        $coupon = $this->record;
        $this->before = $coupon->only(['code', 'plan_id', 'trial_days', 'max_redemptions', 'expires_at', 'active', 'note']);
    }

    protected function afterSave(): void
    {
        /** @var Coupon $coupon */
        $coupon = $this->record;
        $after = $coupon->only(array_keys($this->before));

        if ($after != $this->before) {
            AuditLog::record('admin.coupon_changed', $coupon, ['before' => $this->before, 'after' => $after]);
        }
    }
}
