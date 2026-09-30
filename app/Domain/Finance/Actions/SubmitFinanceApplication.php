<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Lenders\LenderConnections;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Models\FinanceMessage;
use App\Domain\Finance\Models\Lender;
use App\Domain\Finance\Notifications\LenderAlert;
use App\Domain\Finance\Support\FinanceLeadNotice;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Support\Name;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A buyer applies for a car loan with the lender they picked (TDD M10). Nothing is sent without consent, and only to
 * that lender, which must be active and lend for this car. Portal lenders' teams are told; API lenders get it posted
 * and the demo lender answers at once. The lot gets a "Car loan" lead (FinanceLeadNotice) without the buyer's income
 * or employer.
 */
class SubmitFinanceApplication
{
    public function __construct(
        private readonly LenderConnections $connections,
        private readonly UpdateFinanceApplication $update,
        private readonly FinanceLeadNotice $notice,
    ) {}

    /** @param array{monthly_income: int, monthly_commitments: int, employment: string, employer?: ?string, deposit: int, tenor_months: int, consent: bool} $data whole naira */
    public function run(User $buyer, Vehicle $vehicle, Lender $lender, array $data): FinanceApplication
    {
        if (! $vehicle->lot->takesFinance()) {
            throw ValidationException::withMessages(['monthly_income' => "{$vehicle->lot->name} isn't taking car loan applications right now."]);
        }
        if (! $data['consent']) {
            throw ValidationException::withMessages(['consent' => "Tick the box to agree to share your details with {$lender->name}."]);
        }

        $price = intdiv((int) $vehicle->price, 100);
        if ($data['deposit'] >= $price) {
            throw ValidationException::withMessages(['deposit' => 'With that deposit you don\'t need a loan.']);
        }
        if (! $lender->lendsFor($price * 100, $data['deposit'] * 100, $data['tenor_months'], $vehicle->lot->state)) {
            throw ValidationException::withMessages(['lender' => "{$lender->name} doesn't lend for this car on these terms. Try another lender, a bigger deposit or another term."]);
        }
        $open = FinanceApplication::query()->where('user_id', $buyer->id)->where('vehicle_id', $vehicle->id)->where('lender_id', $lender->id)
            ->whereIn('status', FinanceStatus::open())->exists();
        if ($open) {
            throw ValidationException::withMessages(['lender' => "You already have an application with {$lender->name} for this car. Follow it on your applications page."]);
        }

        $application = FinanceApplication::create([
            'user_id' => $buyer->id,
            'vehicle_id' => $vehicle->id,
            'lot_id' => $vehicle->lot_id,
            'lender_id' => $lender->id,
            'partner' => $lender->slug,
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
            'status' => FinanceStatus::Submitted,
            'buyer_read_at' => now(),
        ]);
        $application->setRelation('lender', $lender);
        FinanceMessage::create(['finance_application_id' => $application->id, 'side' => FinanceMessage::SYSTEM, 'body' => "Sent to {$lender->name} with the buyer's consent."]);

        try {
            $answer = $this->connections->for($lender)->submit($application);
        } catch (Throwable $e) {
            report($e);
            $this->update->run($application, FinanceStatus::Failed, ['message' => "We couldn't reach {$lender->name}. Nothing was shared; try again later."]);

            return $application->refresh();
        }

        if ($answer !== null) {
            $application->update(['external_ref' => $answer['reference']]);
        }

        // The lot learns that the buyer applied; the lender's team hears about it in the portal.
        $this->notice->applied($application);
        Notification::send($lender->members()->get(), new LenderAlert(
            'New car loan application: '.Name::short($buyer->name).' for the '.$vehicle->title().' ('.$application->money().", {$application->tenor_months} months).",
            route('lender.applications.show', [$lender, $application]),
        ));

        $status = $answer !== null ? FinanceStatus::tryFrom($answer['status']) : null;
        if ($status !== null && $status !== FinanceStatus::Submitted) {
            $this->update->run($application, $status, [
                'message' => $answer['message'] ?? null,
                'approved_amount' => $answer['approved_amount'] ?? null,
            ]);
        }

        return $application->refresh();
    }
}
