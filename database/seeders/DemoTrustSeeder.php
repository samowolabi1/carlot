<?php

namespace Database\Seeders;

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Enums\AppointmentType;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Domain\Trust\Actions\SaveInspection;
use App\Domain\Trust\Actions\SubmitReview;
use App\Domain\Trust\Enums\InspectorType;
use App\Domain\Trust\Support\InspectionChecklist;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/**
 * S12 demo data on top of DemoMarketplaceSeeder: reviews and an inspection at Demo Lot Ikeja,
 * and a registered inspector (sign in as 08000000200) to try independent inspections.
 *
 *     php artisan db:seed --class=DemoTrustSeeder
 */
class DemoTrustSeeder extends Seeder
{
    private const REVIEWS = [
        ['+2348035550777', 'Tunde Adebayo', 5, ['as_described', 'friendly', 'on_time'], 'Kemi had the car ready when I arrived and let me drive on the expressway.'],
        ['+2348035550778', 'Ada Obi', 4, ['honest_price'], 'Fair price and no pressure. Paperwork took a little while.'],
        ['+2348035550779', 'Musa Bello', 5, ['quick_replies', 'friendly'], null],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo seeder is for local development only.');
        }

        config(['queue.default' => 'sync']);
        Queue::setDefaultDriver('sync');

        $lot = Lot::where('name', 'Demo Seller Ikeja')->first() ?? throw new RuntimeException('Run DemoMarketplaceSeeder first.');

        foreach (self::REVIEWS as $i => [$phone, $name, $rating, $tags, $body]) {
            $buyer = User::firstOrCreate(['phone' => $phone], ['name' => $name, 'phone_verified_at' => now()]);
            $start = now()->subDays(10 + $i * 4)->setTime(10, 0);
            $visit = Appointment::withoutGlobalScopes()->where('lot_id', $lot->id)->where('customer_id', $buyer->id)->where('status', AppointmentStatus::Completed)->first()
                ?? tap(new Appointment, fn (Appointment $a) => $a->forceFill([
                    'lot_id' => $lot->id, 'customer_id' => $buyer->id, 'type' => $i === 0 ? AppointmentType::TestDrive : AppointmentType::Viewing,
                    'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30), 'status' => AppointmentStatus::Completed,
                    'confirmed_at' => $start->copy()->subDay(), 'checked_in_at' => $start, 'completed_at' => $start->copy()->addMinutes(40), 'review_invited_at' => $start->copy()->addHours(3),
                ])->save());

            if ($visit->review()->doesntExist()) {
                app(SubmitReview::class)->run($visit, $buyer, $rating, $tags, $body);
            }
        }

        $camry = Vehicle::withoutGlobalScopes()->where('lot_id', $lot->id)->where('slug', 'like', '2018-toyota-camry%')->first();
        if ($camry !== null && $camry->inspection_id === null) {
            $checklist = collect(InspectionChecklist::keys())->mapWithKeys(fn ($k) => [$k => ['status' => 'pass', 'note' => null]])->all();
            $checklist['paint'] = ['status' => 'advisory', 'note' => 'Light stone chips on the bonnet'];
            $checklist['tread_front'] = ['status' => 'advisory', 'note' => 'About 4 mm left'];
            app(SaveInspection::class)->run($camry, $lot->owner, InspectorType::Dealer, $checklist, 'Serviced before listing. Duty paid, papers ready.', [], 'Demo workshop');
        }

        $inspector = User::firstOrCreate(['phone' => '+2348000000200'], ['name' => 'Tayo Bello', 'phone_verified_at' => now()]);
        $inspector->forceFill(['inspector_since' => $inspector->inspector_since ?? now(), 'inspector_company' => 'AutoCheck NG'])->save();
    }
}
