<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Analytics\Support\Tracker;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Leads\Events\LeadCreated;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Notifications\NewLeadAlert;
use App\Domain\LotManager\Enums\CustomerSource;
use App\Domain\LotManager\Support\CustomerBook;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Realtime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class CaptureLead
{
    /**
     * CaptureLead (TDD M11): every enquiry becomes a lead, de-duplicated by lot, buyer and
     * car within 30 days, and the buyer lands in the lot's customer book (matched by phone).
     */
    public function run(Lot $lot, User $customer, LeadSource $source, ?Vehicle $vehicle = null): Lead
    {
        $stage = $source === LeadSource::Booking ? LeadStage::TestDrive : LeadStage::New;

        $lead = DB::transaction(function () use ($lot, $customer, $source, $vehicle, $stage): Lead {
            $existing = Lead::withoutGlobalScopes()
                ->where('lot_id', $lot->id)
                ->where('customer_id', $customer->id)
                ->where('vehicle_id', $vehicle?->id)
                ->whereNotIn('stage', [LeadStage::Won, LeadStage::Lost])
                ->where('last_activity_at', '>=', now()->subDays(30))
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->last_activity_at = now();
                if ($existing->stage->rank() < $stage->rank()) {
                    $existing->stage = $stage;
                }
                $existing->save();

                return $existing;
            }

            $book = CustomerBook::match($lot, [
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'source' => CustomerSource::Marketplace->value,
            ], now());

            return Lead::withoutGlobalScopes()->create([
                'lot_id' => $lot->id,
                'vehicle_id' => $vehicle?->id,
                'customer_id' => $customer->id,
                'lot_customer_id' => $book->id,
                'source' => $source,
                'stage' => $stage,
                'last_activity_at' => now(),
            ]);
        });

        if ($lead->wasRecentlyCreated) {
            Tracker::record('lead', $lot->id, $vehicle?->id, $source->value);
            Realtime::send(new LeadCreated($lead));
            Notification::send($lot->members()->get(), new NewLeadAlert($lead));
        }

        return $lead;
    }
}
