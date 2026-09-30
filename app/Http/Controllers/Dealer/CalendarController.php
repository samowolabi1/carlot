<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Appointments\Actions\CancelAppointment;
use App\Domain\Appointments\Actions\RescheduleAppointment;
use App\Domain\Appointments\Actions\UpdateAppointmentStatus;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Enums\AppointmentType;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Support\SlotGenerator;
use App\Domain\Location\Actions\StartLocationSession;
use App\Domain\Location\Models\LocationSession;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotHour;
use App\Domain\Lots\Models\LotMember;
use App\Domain\Support\Fields;
use App\Domain\Support\Name;
use App\Domain\Support\PhoneNumber;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Dealer calendar (design D4): week grid, today's visits and requests to confirm. */
class CalendarController extends Controller
{
    public function index(Request $request, Lot $lot): Response
    {
        $tz = $lot->timezone;
        $today = CarbonImmutable::now($tz)->startOfDay();
        $weekStart = rescue(fn () => CarbonImmutable::parse((string) $request->query('week'), $tz), $today, false)->startOfWeek(CarbonImmutable::MONDAY);
        $weekEnd = $weekStart->addDays(7);

        $load = fn ($query) => $query->with(['customer', 'staff', 'vehicle.make', 'vehicle.model'])->get();

        $week = $load(Appointment::query()
            ->whereBetween('starts_at', [$weekStart->utc(), $weekEnd->utc()])
            ->whereNotIn('status', [AppointmentStatus::Cancelled, AppointmentStatus::AwaitingDeposit])
            ->orderBy('starts_at'));

        $todayList = $load(Appointment::query()
            ->whereBetween('starts_at', [$today->utc(), $today->addDay()->utc()])
            ->whereNotIn('status', [AppointmentStatus::Cancelled, AppointmentStatus::AwaitingDeposit])
            ->orderBy('starts_at'));

        $pending = $load(Appointment::query()->where('status', AppointmentStatus::Pending)->where('starts_at', '>', now())->orderBy('starts_at'));

        $hours = LotHour::query()->where('is_closed', false)->get();
        $firstHour = (int) ($hours->min(fn ($h) => (int) substr((string) $h->opens_at, 0, 2)) ?? 8);
        $lastHour = (int) ($hours->max(fn ($h) => (int) ceil(((int) substr((string) $h->closes_at, 0, 2) * 60 + (int) substr((string) $h->closes_at, 3, 2)) / 60)) ?? 18);

        // Stretch the grid over visits outside opening hours (hours changed after booking, or booked by hand),
        // otherwise they'd be drawn off the grid where nobody can see or move them.
        foreach ($week as $a) {
            $start = $a->starts_at->copy()->setTimezone($tz);
            $end = $a->ends_at->copy()->setTimezone($tz);
            $firstHour = min($firstHour, $start->hour);
            $lastHour = max($lastHour, $end->isSameDay($start) ? (int) ceil(($end->hour * 60 + $end->minute) / 60) : 24);
        }

        return Inertia::render('Dealer/Calendar', [
            'week' => [
                'start' => $weekStart->toDateString(),
                'label' => $weekStart->format('j M').' – '.$weekStart->addDays(6)->format('j M Y'),
                'prev' => $weekStart->subWeek()->toDateString(),
                'next' => $weekStart->addWeek()->toDateString(),
                'days' => collect(range(0, 6))->map(fn ($i) => [
                    'date' => $weekStart->addDays($i)->toDateString(),
                    'weekday' => strtoupper($weekStart->addDays($i)->format('D')),
                    'day' => $weekStart->addDays($i)->day,
                    'today' => $weekStart->addDays($i)->equalTo($today),
                    'closed' => ! $hours->contains('weekday', $weekStart->addDays($i)->dayOfWeek),
                ]),
                'first_hour' => min($firstHour, 23),
                'last_hour' => max($lastHour, $firstHour + 1),
            ],
            'appointments' => $week->map(fn (Appointment $a) => $this->present($a, $tz)),
            'today' => ['label' => $today->format('D j M'), 'items' => $todayList->map(fn (Appointment $a) => $this->present($a, $tz))],
            'pending' => $pending->map(fn (Appointment $a) => $this->present($a, $tz)),
            'types' => AppointmentType::options(),
            'staff' => LotMember::query()->where('lot_id', $lot->id)->with('user')->get()->map(fn (LotMember $m) => ['ulid' => $m->user->ulid, 'name' => $m->user->name ?? $m->user->phone]),
            'canAssign' => $request->user()->hasLotRole($lot, LotRole::Owner, LotRole::Manager),
            'isCurrentWeek' => $weekStart->equalTo($today->startOfWeek(CarbonImmutable::MONDAY)),
        ]);
    }

    public function update(Request $request, Lot $lot, Appointment $appointment, UpdateAppointmentStatus $status, RescheduleAppointment $reschedule): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['nullable', Rule::in(UpdateAppointmentStatus::ACTIONS)],
            'starts_at' => ['nullable', 'date'],
            'date' => ['nullable', 'date_format:Y-m-d', 'required_with:time'],
            'time' => ['nullable', 'date_format:H:i', 'required_with:date'],
            'staff' => Fields::ulid(required: false),
        ]);

        if ($request->has('staff')) {
            Gate::authorize('assign', $appointment);
            $staffId = $data['staff']
                ? LotMember::query()->where('lot_id', $lot->id)->whereHas('user', fn ($q) => $q->where('ulid', $data['staff']))->value('user_id') ?? abort(422, 'Not a member of this lot.')
                : null;
            $appointment->forceFill(['staff_id' => $staffId])->save();

            return back()->with('success', $staffId ? 'Assigned.' : 'Unassigned.');
        }

        Gate::authorize('manage', $appointment);

        // A drop on the calendar grid sends the lot-local date and time.
        $start = ! empty($data['date'])
            ? CarbonImmutable::parse("{$data['date']} {$data['time']}", $lot->timezone)
            : (! empty($data['starts_at']) ? CarbonImmutable::parse($data['starts_at']) : null);

        if ($start !== null) {
            $reschedule->run($appointment, $start, $request->user());

            return back()->with('success', 'Moved. The buyer has been told.');
        }

        abort_if(empty($data['action']), 422);
        $status->run($appointment, $data['action']);

        return back()->with('success', match ($data['action']) {
            'confirm' => 'Confirmed. The buyer has been told.',
            'check_in' => 'Checked in.',
            'no_show' => 'Marked as no-show.',
            default => 'Visit completed.',
        });
    }

    public function cancel(Request $request, Lot $lot, Appointment $appointment, CancelAppointment $cancel): RedirectResponse
    {
        Gate::authorize('manage', $appointment);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $cancel->run($appointment, $request->user(), $data['reason'] ?? null);

        return back()->with('success', 'Booking cancelled. The buyer has been told.');
    }

    /** Free slots for moving a booking (its own place counts as free). */
    public function slots(Lot $lot, Appointment $appointment, SlotGenerator $slots): JsonResponse
    {
        Gate::authorize('manage', $appointment);

        return response()->json($slots->days($lot, ignore: $appointment));
    }

    /** @var Collection<int, Collection<int, LocationSession>>|null */
    private $liveLocations = null;

    private function present(Appointment $a, string $tz): array
    {
        $start = $a->starts_at->copy()->setTimezone($tz);
        $this->liveLocations ??= LocationSession::query()->live()->with('sharer')->get()->groupBy('appointment_id');

        return [
            'ulid' => $a->ulid,
            'type' => $a->type->value,
            'type_label' => $a->type->label(),
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'date' => $start->toDateString(),
            'time' => $start->format('H:i'),
            'minutes' => (int) $a->starts_at->diffInMinutes($a->ends_at),
            'when' => $start->format('D j M, H:i'),
            'customer' => $a->customer->name ?? 'Buyer',
            // Buyers who book have engaged with the lot, so their number is shown (TDD: Privacy).
            'phone' => $a->customer->phone,
            'phone_display' => PhoneNumber::display($a->customer->phone),
            'whatsapp' => $a->customer->phone ? ltrim($a->customer->phone, '+') : null,
            'car' => $a->vehicle?->title(),
            'notes' => $a->notes,
            'staff' => $a->staff ? ['ulid' => $a->staff->ulid, 'name' => $a->staff->name] : null,
            'checked_in' => $a->checked_in_at !== null,
            'past' => $a->starts_at->isPast(),
            // Live location (TDD M8): follow a buyer on the way, or share the lot's.
            'location' => [
                'can_share' => StartLocationSession::canShare($a),
                'live' => $this->liveLocations->get($a->id, collect())->map(fn (LocationSession $s) => [
                    'ulid' => $s->ulid,
                    'who' => $s->sharer_side === LocationSession::CUSTOMER ? Name::short($a->customer->name ?? 'Buyer') : ($s->sharer->name ?? 'Your team'),
                    'side' => $s->sharer_side,
                ])->values(),
            ],
        ];
    }
}
