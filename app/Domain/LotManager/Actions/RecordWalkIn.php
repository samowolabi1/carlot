<?php

namespace App\Domain\LotManager\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Enums\FollowUpType;
use App\Domain\LotManager\Enums\Interest;
use App\Domain\LotManager\Enums\NextStep;
use App\Domain\LotManager\Models\FollowUpTask;
use App\Domain\LotManager\Models\WalkIn;
use App\Domain\LotManager\Support\CustomerBook;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotHour;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RecordWalkIn
{
    /**
     * A visitor at the gate, in under 30 seconds: only name and phone are required
     * (TDD M19). Safe to replay: the same client_uuid gives the same walk-in.
     *
     * @param  array{name: string, phone: string, email?: ?string, source?: ?string, interest?: ?string, next_step?: ?string, vehicles?: ?list<string>, budget_max?: ?int, notes?: ?string, consent_whatsapp?: ?bool, visited_at?: ?string, client_uuid?: ?string}  $data
     */
    public function run(Lot $lot, User $staff, array $data): WalkIn
    {
        if (filled($data['client_uuid'] ?? null) && ($existing = $this->find($lot, $data['client_uuid']))) {
            return $existing;
        }

        $visitedAt = filled($data['visited_at'] ?? null) ? Carbon::parse($data['visited_at'])->utc() : now();
        if ($visitedAt->isFuture()) {
            $visitedAt = now();
        }

        try {
            return DB::transaction(function () use ($lot, $staff, $data, $visitedAt): WalkIn {
                $customer = CustomerBook::match($lot, $data, $visitedAt);
                $nextStep = NextStep::tryFrom($data['next_step'] ?? '') ?? NextStep::None;
                $followUp = $nextStep === NextStep::CallBack ? $this->nextWorkingMorning($lot, $visitedAt) : null;

                $vehicleIds = empty($data['vehicles']) ? null : Vehicle::withoutGlobalScopes()
                    ->where('lot_id', $lot->id)
                    ->whereIn('ulid', $data['vehicles'])
                    ->pluck('id')
                    ->all();

                $walkIn = WalkIn::withoutGlobalScopes()->create([
                    'lot_id' => $lot->id,
                    'lot_customer_id' => $customer->id,
                    'staff_id' => $staff->id,
                    'visited_at' => $visitedAt,
                    'vehicles_viewed' => $vehicleIds ?: null,
                    'interest' => Interest::tryFrom($data['interest'] ?? '') ?? Interest::Browsing,
                    'next_step' => $nextStep,
                    'follow_up_at' => $followUp,
                    'notes' => $data['notes'] ?? null,
                    'client_uuid' => $data['client_uuid'] ?? null,
                ]);

                if ($followUp !== null) {
                    FollowUpTask::withoutGlobalScopes()->create([
                        'lot_id' => $lot->id,
                        'lot_customer_id' => $customer->id,
                        'walk_in_id' => $walkIn->id,
                        'assigned_to' => $staff->id,
                        'type' => FollowUpType::Call,
                        'due_at' => $followUp,
                        'note' => $data['notes'] ?? null,
                    ]);
                }

                return $walkIn;
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same offline item arrived twice at once; the first one won.
            return $this->find($lot, (string) ($data['client_uuid'] ?? '')) ?? throw $e;
        }
    }

    private function find(Lot $lot, string $clientUuid): ?WalkIn
    {
        return WalkIn::withoutGlobalScopes()->where('lot_id', $lot->id)->where('client_uuid', $clientUuid)->first();
    }

    /** 10:00 lot time on the next day the lot is open (the next day if no hours are set). */
    public function nextWorkingMorning(Lot $lot, Carbon $from): CarbonImmutable
    {
        $open = LotHour::withoutGlobalScopes()->where('lot_id', $lot->id)->where('is_closed', false)->pluck('weekday')->all();
        $day = CarbonImmutable::parse($from)->setTimezone($lot->timezone)->startOfDay()->addDay();

        for ($i = 0; $open !== [] && $i < 7 && ! in_array($day->dayOfWeek, $open, true); $i++) {
            $day = $day->addDay();
        }

        return $day->setTime(10, 0)->utc();
    }
}
