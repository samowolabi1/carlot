<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Leads\Models\Lead;
use App\Domain\Lots\Models\LotMember;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class UpdateLead
{
    /**
     * Stage, assignment and follow-up from the board or the lead page.
     *
     * @param  array{stage?: ?string, assigned_to?: ?string, next_follow_up_at?: ?string, lost_reason?: ?string}  $data  assigned_to is a user ULID
     */
    public function run(Lead $lead, User $user, array $data, string $timezone): Lead
    {
        $before = $lead->only(['stage', 'assigned_to']);

        if (array_key_exists('stage', $data) && $data['stage'] !== null) {
            $stage = LeadStage::from($data['stage']);

            if ($stage === LeadStage::Lost && blank($data['lost_reason'] ?? $lead->lost_reason)) {
                throw ValidationException::withMessages(['lost_reason' => 'Say why the lead was lost.']);
            }

            $lead->stage = $stage;
            $lead->closed_at = $stage->isClosed() ? ($lead->closed_at ?? now()) : null;
            $lead->lost_reason = $stage === LeadStage::Lost ? ($data['lost_reason'] ?? $lead->lost_reason) : null;
        }

        if (array_key_exists('assigned_to', $data)) {
            $lead->assigned_to = $data['assigned_to'] === null ? null
                : (LotMember::query()->where('lot_id', $lead->lot_id)->whereHas('user', fn ($q) => $q->where('ulid', $data['assigned_to']))->value('user_id')
                    ?? throw ValidationException::withMessages(['assigned_to' => 'Pick someone who works for this seller.']));
        }

        if (array_key_exists('next_follow_up_at', $data)) {
            $lead->next_follow_up_at = $data['next_follow_up_at'] ? Carbon::parse($data['next_follow_up_at'], $timezone)->utc() : null;
            $lead->follow_up_reminded_at = null;
        }

        $lead->last_activity_at = now();
        $lead->save();

        if ($lead->wasChanged(['stage', 'assigned_to'])) {
            AuditLog::record('lead.updated', $lead, ['before' => $before, 'after' => $lead->only(['stage', 'assigned_to'])], $user, $lead->lot_id);
        }

        return $lead;
    }
}
