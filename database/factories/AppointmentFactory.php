<?php

namespace Database\Factories;

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Enums\AppointmentType;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Lots\Models\Lot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $start = now()->addDays(2)->setTime(10, 0);

        return [
            'lot_id' => Lot::factory(),
            'customer_id' => User::factory(),
            'type' => AppointmentType::Viewing,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes(30),
            'status' => AppointmentStatus::Confirmed,
            'confirmed_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => AppointmentStatus::Pending, 'confirmed_at' => null]);
    }

    public function at(\DateTimeInterface $start, int $minutes = 30): static
    {
        return $this->state(['starts_at' => $start, 'ends_at' => Carbon::instance($start)->addMinutes($minutes)]);
    }
}
