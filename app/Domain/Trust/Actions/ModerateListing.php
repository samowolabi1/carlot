<?php

namespace App\Domain\Trust\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Trust\Enums\ReportStatus;
use App\Domain\Trust\Enums\SignalStatus;
use App\Domain\Trust\Models\FraudSignal;
use App\Domain\Trust\Models\Report;
use App\Domain\Trust\Notifications\ModerationNotice;
use Illuminate\Support\Facades\DB;

/**
 * An admin's decision on a flagged or reported listing (design A1): hide it (it stays off the
 * marketplace and the lot can't republish it) or approve it (back on sale; the reports and
 * signals are closed). Either way the lot is told.
 */
class ModerateListing
{
    public function hide(Vehicle $vehicle, User $admin, string $reason): void
    {
        DB::transaction(function () use ($vehicle, $admin, $reason): void {
            // save() so search drops it straight away.
            $vehicle->held_at = $vehicle->held_at ?? now();
            $vehicle->held_reason = 'Hidden by LotLink: '.$reason;
            $vehicle->save();

            $this->close($vehicle, $admin, ReportStatus::Actioned, SignalStatus::Actioned);
            AuditLog::record('admin.listing_hidden', $vehicle, ['reason' => $reason], $admin, $vehicle->lot_id);
            $vehicle->lot->owner->notify(new ModerationNotice($vehicle->lot, "{$vehicle->title()} was taken off LotLink: {$reason}. Contact LotLink support if you think this is a mistake."));
        });
    }

    public function approve(Vehicle $vehicle, User $admin): void
    {
        DB::transaction(function () use ($vehicle, $admin): void {
            $wasHeld = $vehicle->isHeld();
            $vehicle->held_at = null;
            $vehicle->held_reason = null;
            $vehicle->save();

            $this->close($vehicle, $admin, ReportStatus::Dismissed, SignalStatus::Cleared);
            AuditLog::record('admin.listing_approved', $vehicle, [], $admin, $vehicle->lot_id);

            if ($wasHeld) {
                $vehicle->lot->owner->notify(new ModerationNotice($vehicle->lot, "{$vehicle->title()} has been checked and is back on LotLink."));
            }
        });
    }

    private function close(Vehicle $vehicle, User $admin, ReportStatus $reports, SignalStatus $signals): void
    {
        Report::where('reportable_type', Vehicle::class)->where('reportable_id', $vehicle->id)->where('status', ReportStatus::Open)
            ->update(['status' => $reports, 'handled_by' => $admin->id, 'handled_at' => now()]);
        FraudSignal::where('vehicle_id', $vehicle->id)->where('status', SignalStatus::Open)
            ->update(['status' => $signals, 'handled_by' => $admin->id, 'handled_at' => now()]);
    }
}
