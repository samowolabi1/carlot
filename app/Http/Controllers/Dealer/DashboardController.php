<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Analytics\Models\DailyVehicleStat;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Leads\Models\Lead;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\Name;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Lot $lot): Response
    {
        $lot->loadCount(['members', 'hours', 'invitations' => fn ($q) => $q->pending()]);

        return Inertia::render('Dealer/Dashboard', [
            'checklist' => [
                ['key' => 'business', 'label' => 'Business details', 'done' => true],
                ['key' => 'branding', 'label' => 'Logo and cover', 'done' => $lot->logo_path !== null],
                ['key' => 'location', 'label' => 'Pin your location', 'done' => $lot->hasLocation()],
                ['key' => 'hours', 'label' => 'Opening hours', 'done' => $lot->hours_count === 7],
                ['key' => 'staff', 'label' => 'Invite staff', 'done' => $lot->members_count > 1 || $lot->invitations_count > 0],
                ['key' => 'submit', 'label' => 'Submit for approval', 'done' => $lot->submitted_at !== null],
            ],
            'staffCount' => $lot->members_count,
            'visits' => [
                'today' => Appointment::query()
                    ->whereIn('status', [AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::Completed])
                    ->whereBetween('starts_at', [now($lot->timezone)->startOfDay()->utc(), now($lot->timezone)->endOfDay()->utc()])
                    ->with(['customer', 'vehicle.make', 'vehicle.model', 'staff'])
                    ->orderBy('starts_at')
                    ->get()
                    ->map(fn (Appointment $a) => [
                        'ulid' => $a->ulid,
                        'time' => $a->starts_at->copy()->setTimezone($lot->timezone)->format('H:i'),
                        'customer' => Name::short($a->customer->name),
                        'type' => $a->type->label(),
                        'status' => $a->status->value,
                        'checked_in' => $a->checked_in_at !== null,
                        'staff' => $a->staff?->name ? strtok($a->staff->name, ' ') : null,
                    ]),
                'week' => Appointment::query()->active()->whereBetween('starts_at', [now($lot->timezone)->startOfWeek()->utc(), now($lot->timezone)->endOfWeek()->utc()])->count(),
                'pending' => Appointment::query()->where('status', AppointmentStatus::Pending)->where('starts_at', '>', now())->count(),
            ],
            // The other KPI tiles: views from the daily roll-up (like Analytics), leads still waiting for a first reply,
            // and cars sold this calendar month in the seller's timezone.
            'kpis' => [
                'views' => (int) DailyVehicleStat::query()->where('date', '>=', now($lot->timezone)->subDays(7)->toDateString())->sum('views'),
                'new_leads' => Lead::query()->where('stage', LeadStage::New)->count(),
                'sold' => Vehicle::query()->where('status', VehicleStatus::Sold)->where('sold_at', '>=', now($lot->timezone)->startOfMonth()->utc())->count(),
            ],
            'stock' => [
                'live' => Vehicle::live()->count(),
                'drafts' => Vehicle::where('status', VehicleStatus::Draft)->count(),
                'ageing' => Vehicle::where('status', VehicleStatus::Available)->where('listed_at', '<=', now()->subDays(Vehicle::AGEING_DAYS))->count(),
                'limit' => $lot->plan()->value('listing_limit'),
            ],
        ]);
    }
}
