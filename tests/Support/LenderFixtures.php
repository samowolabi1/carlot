<?php

namespace Tests\Support;

use App\Domain\Accounts\Models\User;
use App\Domain\Finance\Enums\LenderIntegration;
use App\Domain\Finance\Enums\LenderRole;
use App\Domain\Finance\Enums\LenderStatus;
use App\Domain\Finance\Enums\LenderType;
use App\Domain\Finance\Lenders\LenderConnection;
use App\Domain\Finance\Lenders\LenderConnections;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Models\Lender;
use App\Domain\Legal\LegalDocuments;
use Closure;
use Illuminate\Support\Str;

/** Lenders for tests: active by default, answering in the portal unless given another integration. */
trait LenderFixtures
{
    /** @param array<string, mixed> $attributes */
    protected function lender(array $attributes = [], ?User $admin = null): Lender
    {
        $name = $attributes['name'] ?? 'Kobo Motor Finance';
        $lender = Lender::create([
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => $name,
            'status' => LenderStatus::Active,
            'licence_type' => LenderType::FinanceCompany,
            'licence_number' => 'FC-1234',
            'contact_name' => 'Kemi Adeyemi',
            'contact_email' => 'loans@kobo.test',
            'contact_phone' => '+2348031112222',
            'rate_bp' => 2400,
            'min_amount' => 100_000_000,
            'max_amount' => 5_000_000_000,
            'min_deposit_percent' => 10,
            'tenors' => [12, 24, 36, 48],
            'integration' => LenderIntegration::Portal,
            'terms_version' => LegalDocuments::version('lender-terms'),
            'terms_accepted_at' => now(),
            ...$attributes,
        ]);
        if ($admin !== null) {
            $lender->members()->attach($admin->id, ['role' => LenderRole::Admin->value]);
        }

        return $lender;
    }

    /**
     * Stands in for every lender's system: $answer gets the application and returns the first answer (null: waits in the portal).
     *
     * @param  Closure(FinanceApplication): ?array<string, mixed>  $answer
     */
    protected function lenderAnswers(Closure $answer): void
    {
        $this->app->instance(LenderConnections::class, new class($answer) extends LenderConnections
        {
            public function __construct(private readonly Closure $answer) {}

            public function for(Lender $lender): LenderConnection
            {
                return new class($this->answer) implements LenderConnection
                {
                    public function __construct(private readonly Closure $answer) {}

                    public function submit(FinanceApplication $application): ?array
                    {
                        return ($this->answer)($application);
                    }
                };
            }
        });
    }
}
