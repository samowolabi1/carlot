<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Notifications\FinanceUpdate;
use App\Domain\Finance\Partners\FinancePartner;
use App\Domain\Finance\Support\FinanceLeadNotice;
use App\Domain\Inventory\Models\Vehicle;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pre-qualification hand-off (TDD M10): with the buyer's consent, send their details to the
 * finance partner and keep the reference and status. Nothing is sent without consent. The lot
 * gets a "Car loan" lead (FinanceLeadNotice) without the buyer's income or employer.
 */
class SubmitFinanceApplication
{
    public function __construct(private readonly FinancePartner $partner, private readonly FinanceLeadNotice $notice) {}

    /** @param array{monthly_income: int, monthly_commitments: int, employment: string, employer?: ?string, deposit: int, tenor_months: int, consent: bool} $data whole naira */
    public function run(User $buyer, Vehicle $vehicle, array $data): FinanceApplication
    {
        if (! $vehicle->lot->takesFinance()) {
            throw ValidationException::withMessages(['monthly_income' => "{$vehicle->lot->name} isn't taking car loan applications right now."]);
        }
        if (! $data['consent']) {
            throw ValidationException::withMessages(['consent' => 'Tick the box to agree to share your details with '.$this->partner->name().'.']);
        }

        $price = intdiv((int) $vehicle->price, 100);
        if ($data['deposit'] >= $price) {
            throw ValidationException::withMessages(['deposit' => 'With that deposit you don\'t need a loan.']);
        }

        $application = FinanceApplication::create([
            'user_id' => $buyer->id,
            'vehicle_id' => $vehicle->id,
            'lot_id' => $vehicle->lot_id,
            'partner' => $this->partner->code(),
            'amount' => ($price - $data['deposit']) * 100,
            'deposit' => $data['deposit'] * 100,
            'tenor_months' => $data['tenor_months'],
            'currency' => $vehicle->currency,
            'applicant' => [
                'name' => $buyer->name,
                'phone' => $buyer->phone,
                'email' => $buyer->email,
                'monthly_income' => $data['monthly_income'],
                'monthly_commitments' => $data['monthly_commitments'],
                'employment' => $data['employment'],
                'employer' => $data['employer'] ?? null,
            ],
            'consented_at' => now(),
            'status' => 'submitted',
        ]);

        try {
            $answer = $this->partner->submit($application);
            $application->update([
                'external_ref' => $answer['reference'],
                'status' => $answer['status'],
                'partner_message' => isset($answer['message']) ? mb_substr((string) $answer['message'], 0, 255) : null,
                'approved_amount' => $answer['approved_amount'] ?? null,
            ]);
        } catch (Throwable $e) {
            report($e);
            $application->update(['status' => 'failed', 'partner_message' => 'We couldn\'t reach '.$this->partner->name().'. Nothing was shared; try again later.']);
        }

        if (in_array($application->status, ['pre_approved', 'declined'], true)) {
            $buyer->notify(new FinanceUpdate($application));
        }

        // The lot learns that the buyer applied (and any pre-approval), never the private details or a decline.
        if ($application->status !== 'failed') {
            $this->notice->applied($application);
            if ($application->status === 'pre_approved') {
                $this->notice->preApproved($application);
            }
        }

        return $application;
    }
}
