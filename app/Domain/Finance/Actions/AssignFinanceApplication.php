<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Notifications\LenderAlert;
use Illuminate\Validation\ValidationException;

/** Hands an application to one of the lender's officers (or back to the whole team). */
class AssignFinanceApplication
{
    public function run(FinanceApplication $application, ?User $officer, User $by): FinanceApplication
    {
        if ($officer !== null && $application->lender->roleOf($officer) === null) {
            throw ValidationException::withMessages(['assigned_to' => 'Pick someone on your team.']);
        }

        $application->update(['assigned_to' => $officer?->id]);
        AuditLog::record('finance.assigned', $application, ['to' => $officer?->ulid], $by);

        if ($officer !== null && ! $officer->is($by)) {
            $officer->notify(new LenderAlert(
                "{$by->name} gave you the car loan application for the ".($application->vehicle?->title() ?? 'car').'.',
                route('lender.applications.show', [$application->lender, $application]),
                email: false,
            ));
        }

        return $application;
    }
}
