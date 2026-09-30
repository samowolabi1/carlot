<?php

namespace App\Domain\Finance\Support;

use App\Domain\Deals\Notifications\DealAlert;
use App\Domain\Deals\Support\DealTimeline;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Leads\Actions\CaptureLead;
use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Models\Lead;
use App\Domain\Support\Money;
use App\Domain\Support\Name;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the lot about a car loan application on one of its cars, without the private part: that the buyer
 * applied (a "Car loan" lead with a line in the chat), and later that they were pre-approved and for how much.
 * Income, commitments, employer and declines are never shared with the lot.
 */
class FinanceLeadNotice
{
    public function __construct(private readonly CaptureLead $capture, private readonly DealTimeline $timeline) {}

    public function applied(FinanceApplication $application): void
    {
        $lead = $this->lead($application);
        $car = $application->vehicle->title();

        $this->timeline->post($lead, "Applied for a car loan on the {$car} ({$application->tenor_months} months).");
        $this->alert($application, $lead, Name::short($application->user->name)." applied for a car loan on the {$car}.");
    }

    public function preApproved(FinanceApplication $application): void
    {
        $lead = $this->lead($application);
        $car = $application->vehicle->title();
        $amount = $application->approved_amount ? ' for '.Money::format($application->approved_amount, $application->currency) : '';

        $this->timeline->post($lead, "Pre-approved for a car loan{$amount} on the {$car}.");
        $this->alert($application, $lead, Name::short($application->user->name)." is pre-approved for a car loan{$amount} on the {$car}.");
    }

    private function lead(FinanceApplication $application): Lead
    {
        // CaptureLead finds the buyer's open lead for this car (30 days) or starts one, and adds them to the customer book.
        return $this->capture->run($application->lot, $application->user, LeadSource::Finance, $application->vehicle);
    }

    private function alert(FinanceApplication $application, Lead $lead, string $text): void
    {
        Notification::send($application->lot->members()->get(), new DealAlert('finance', $text, route('dealer.leads.show', [$application->lot, $lead])));
    }
}
