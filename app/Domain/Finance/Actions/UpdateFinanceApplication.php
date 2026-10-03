<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Models\FinanceMessage;
use App\Domain\Finance\Models\Lender;
use App\Domain\Finance\Notifications\FinanceUpdate;
use App\Domain\Finance\Notifications\LenderAlert;
use App\Domain\Finance\Support\FinanceLeadNotice;
use App\Domain\Support\Name;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The only place a car loan application changes status: the lender's team in the portal, the lender's API
 * (signed webhook), or the buyer withdrawing. Locks the row, checks the move is allowed, keeps a line in the
 * application's thread, audit-logs it, tells the buyer (or the lender, for a withdrawal) and tells the seller
 * only the good news (pre-approved, approved, paid).
 */
class UpdateFinanceApplication
{
    public function __construct(private readonly FinanceLeadNotice $notice) {}

    /**
     * @param  array{message?: ?string, next_steps?: ?string, approved_amount?: ?int, offer_rate_bp?: ?int, offer_tenor_months?: ?int, disbursed_amount?: ?int, disbursed_reference?: ?string}  $details  amounts in kobo
     */
    public function run(FinanceApplication $application, FinanceStatus $to, array $details = [], ?User $by = null): FinanceApplication
    {
        [$application, $from] = DB::transaction(function () use ($application, $to, $details, $by) {
            /** @var FinanceApplication $locked */
            $locked = FinanceApplication::query()->lockForUpdate()->findOrFail($application->id);
            $from = $locked->status;

            if (! $from->canMoveTo($to)) {
                throw ValidationException::withMessages(['status' => "This application is already \"{$from->label()}\", so it can't be marked \"{$to->label()}\"."]);
            }

            $message = filled($details['message'] ?? null) ? mb_substr(trim((string) $details['message']), 0, 1000) : null;
            $locked->fill(array_filter([
                'status' => $to,
                'approved_amount' => $details['approved_amount'] ?? null,
                'offer_rate_bp' => $details['offer_rate_bp'] ?? null,
                'offer_tenor_months' => $details['offer_tenor_months'] ?? null,
                'disbursed_amount' => $details['disbursed_amount'] ?? null,
                'disbursed_reference' => $details['disbursed_reference'] ?? null,
                'disbursed_at' => $to === FinanceStatus::Disbursed && $from !== $to ? now() : null,
                'decided_at' => in_array($to, [FinanceStatus::Approved, FinanceStatus::Declined], true) && $from !== $to ? now() : null,
                'lender_read_at' => $to !== FinanceStatus::Withdrawn ? now() : null,
                'buyer_read_at' => $to === FinanceStatus::Withdrawn ? now() : null,
            ], fn ($v) => $v !== null));
            // After a yes, the buyer continues at the lender: its next steps (this application's, else the lender's usual ones).
            if (in_array($to, [FinanceStatus::PreApproved, FinanceStatus::Approved], true)) {
                $steps = filled($details['next_steps'] ?? null) ? trim((string) $details['next_steps']) : null;
                $locked->next_steps = mb_substr($steps ?? $locked->next_steps ?? Lender::whereKey($locked->lender_id)->value('next_steps') ?? '', 0, 1000) ?: null;
            }
            // The lender's latest note: a new status replaces the old one (a "send documents" note shouldn't linger after approval).
            if ($message !== null || $from !== $to) {
                $locked->partner_message = $message !== null ? mb_substr($message, 0, 255) : null;
            }
            $locked->save();

            if ($from !== $to || $message !== null) {
                FinanceMessage::create([
                    'finance_application_id' => $locked->id,
                    'user_id' => $by?->id,
                    'side' => FinanceMessage::SYSTEM,
                    'body' => $this->line($locked, $to, 'thread').($message !== null ? "\n\n{$message}" : ''),
                ]);
            }

            if ($from !== $to) {
                AuditLog::record('finance.status', $locked, ['from' => $from->value, 'to' => $to->value], $by);
            }

            return [$locked, $from];
        });

        if ($from === $to) {
            return $application;
        }

        if ($to === FinanceStatus::Withdrawn) {
            $url = route('lender.applications.show', [$application->lender, $application]);
            Notification::send($application->lender->members()->get(), new LenderAlert($this->line($application, $to, 'lender'), $url, email: false));
        } else {
            $application->user->notify(new FinanceUpdate($application, $this->line($application, $to, 'buyer')));
        }

        // The seller hears only the good news; declines and withdrawals stay between the buyer and the lender.
        if ($application->lot && $application->vehicle) {
            match ($to) {
                FinanceStatus::PreApproved => $this->notice->preApproved($application),
                FinanceStatus::Approved => $this->notice->approved($application),
                FinanceStatus::Disbursed => $this->notice->disbursed($application),
                default => null,
            };
        }

        return $application;
    }

    /** What happened, in words for the buyer, the lender or the application's thread. */
    public function line(FinanceApplication $application, FinanceStatus $status, string $for): string
    {
        $lender = $application->lenderName();
        $car = $application->vehicle?->title() ?? 'the car';
        $approved = $application->money($application->approved_amount ?? $application->amount);
        $terms = collect([
            $application->offer_rate_bp ? $application->lender?->rateLabel($application->offer_rate_bp).' a year' : null,
            $application->offer_tenor_months ? "over {$application->offer_tenor_months} months" : null,
        ])->filter()->implode(' ');

        return match ($status) {
            FinanceStatus::Received => "{$lender} is reviewing the application.",
            FinanceStatus::DocumentsRequested => "{$lender} needs some documents to go on. Upload them on the application.",
            FinanceStatus::PreApproved => "{$lender} pre-approved a loan of {$approved} for the {$car}, subject to its checks. Continue with {$lender} to finish.",
            FinanceStatus::Approved => "{$lender} approved a loan of {$approved} for the {$car}".($terms !== '' ? " at {$terms}" : '').". Continue with {$lender} to sign and complete it.",
            FinanceStatus::Disbursed => "{$lender} paid ".$application->money($application->disbursed_amount ?? $application->approved_amount ?? $application->amount).' to '.($application->lot->name ?? 'the seller')." for the {$car}.",
            FinanceStatus::Declined => "{$lender} couldn't approve the loan for the {$car}.",
            FinanceStatus::Withdrawn => $for === 'lender'
                ? Name::short($application->user->name)." withdrew the application for the {$car}."
                : 'The application was withdrawn.',
            FinanceStatus::Failed => "We couldn't reach {$lender}. Nothing was shared.",
            FinanceStatus::Submitted => "Sent to {$lender}.",
        };
    }
}
