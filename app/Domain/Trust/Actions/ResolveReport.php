<?php

namespace App\Domain\Trust\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Trust\Enums\ReportStatus;
use App\Domain\Trust\Models\Report;

/** Closes a report on a lot or a chat message once an admin has dealt with it. */
class ResolveReport
{
    public function run(Report $report, User $admin, ReportStatus $status): void
    {
        $report->update(['status' => $status, 'handled_by' => $admin->id, 'handled_at' => now()]);
        AuditLog::record('admin.report_'.$status->value, $report, ['kind' => $report->kind(), 'reason' => $report->reason->value], $admin, $report->lot_id);
    }
}
