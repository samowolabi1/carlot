<?php

namespace Database\Seeders;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Finance\Enums\LenderIntegration;
use App\Domain\Finance\Enums\LenderRole;
use App\Domain\Finance\Enums\LenderStatus;
use App\Domain\Finance\Enums\LenderType;
use App\Domain\Finance\Models\Lender;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Two lenders for trying car loans locally: "Demo Finance" answers at once (nothing is sent anywhere) and
 * "Kobo Motor Finance" works applications in the lender portal (sign in as lender@lotlink.test / password).
 */
class DemoLenderSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('The demo seeder is for local development only.');
        }

        $base = [
            'status' => LenderStatus::Active, 'licence_type' => LenderType::FinanceCompany, 'licence_number' => 'DEMO-0001',
            'contact_name' => 'Demo Contact', 'contact_phone' => '+2348000000100', 'min_deposit_percent' => 10,
            'min_amount' => 100_000_000, 'max_amount' => 5_000_000_000, 'tenors' => [12, 24, 36, 48],
        ];

        // A database that had car loan applications before lenders were accounts already has the demo lender (slug "demo").
        $demoSlug = Lender::where('slug', 'demo')->exists() ? 'demo' : 'demo-finance';
        Lender::updateOrCreate(['slug' => $demoSlug], [...$base,
            'name' => 'Demo Finance', 'contact_email' => 'demo-finance@lotlink.test', 'rate_bp' => 2400,
            'integration' => LenderIntegration::Demo, 'about' => 'A stand-in lender that answers straight away. Nothing is sent anywhere.',
        ]);

        $portal = Lender::updateOrCreate(['slug' => 'kobo-motor-finance'], [...$base,
            'name' => 'Kobo Motor Finance', 'contact_email' => 'lender@lotlink.test', 'rate_bp' => 2650, 'min_deposit_percent' => 20,
            'tenors' => [12, 24, 36], 'integration' => LenderIntegration::Portal,
            'about' => 'Car loans for salaried and self-employed buyers in Lagos, Abuja and Port Harcourt.',
        ]);
        $officer = User::updateOrCreate(['email' => 'lender@lotlink.test'], ['name' => 'Kemi Adeyemi', 'password' => 'password', 'role' => UserRole::Customer]);
        $portal->members()->syncWithoutDetaching([$officer->id => ['role' => LenderRole::Admin->value]]);
    }
}
