<?php

namespace App\Http\Presenters;

use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Models\FinanceMessage;
use App\Domain\Finance\Models\Lender;
use App\Domain\Support\Money;
use App\Domain\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Car loan applications for the buyer and the lender. The lender sees what the buyer agreed to share; the buyer sees
 * their own. Neither shape ever goes to the lot (FinanceLeadNotice tells the lot the little it may know).
 */
final class FinancePresenter
{
    public const TZ = 'Africa/Lagos';

    /** A lender as buyers see it on the apply page. @return array<string, mixed> */
    public static function lenderOption(Lender $lender, ?string $state): array
    {
        return [
            'slug' => $lender->slug,
            'name' => $lender->name,
            'about' => $lender->about,
            'type' => $lender->licence_type->label(),
            'rate' => $lender->rate_bp / 100,
            'rate_label' => $lender->rateLabel(),
            'min_amount' => intdiv($lender->min_amount, 100),
            'max_amount' => intdiv($lender->max_amount, 100),
            'min_deposit_percent' => $lender->min_deposit_percent,
            'tenors' => $lender->tenors,
            'in_state' => $lender->states === null || $lender->states === [] || ($state !== null && in_array($state, $lender->states, true)),
            'product' => $lender->productLine(),
            'instant' => $lender->integration->value === 'demo',
        ];
    }

    /** One row in a list. @return array<string, mixed> */
    public static function summary(FinanceApplication $a, string $side): array
    {
        return [
            'ulid' => $a->ulid,
            'car' => $a->vehicle?->title(),
            'car_url' => $a->vehicle?->publicPath(),
            'lot' => $a->lot?->name,
            'lender' => $a->lender?->name,
            'buyer' => $side === FinanceMessage::LENDER ? ($a->applicant['name'] ?? $a->user->name ?? 'Buyer') : null,
            'amount' => $a->money(),
            'approved' => $a->approved_amount ? $a->money($a->approved_amount) : null,
            'months' => $a->tenor_months,
            'status' => $a->status->value,
            'status_label' => $a->statusLabel(),
            'tone' => $a->status->tone(),
            'open' => $a->status->isOpen(),
            'message' => $a->partner_message,
            'reference' => $a->external_ref,
            'assignee' => $side === FinanceMessage::LENDER ? $a->assignee?->name : null,
            'unread' => $a->unreadFor($side),
            'date' => self::date($a->created_at),
            'updated' => $a->updated_at->diffForHumans(),
        ];
    }

    /** The full application with its thread. @return array<string, mixed> */
    public static function detail(FinanceApplication $a, string $side): array
    {
        $a->loadMissing(['vehicle.make', 'vehicle.model', 'vehicle.cover', 'lot', 'lender', 'assignee', 'messages.user']);
        $lender = $a->lender;

        return [
            ...self::summary($a, $side),
            'deposit' => $a->money($a->deposit),
            'numbers' => ['amount' => intdiv($a->amount, 100), 'approved' => $a->approved_amount ? intdiv($a->approved_amount, 100) : null],
            'price' => $a->vehicle ? Money::format((int) $a->vehicle->price, $a->currency) : null,
            'image' => ($urls = $a->vehicle?->cover?->urls() ?? []) ? ($urls[400] ?? reset($urls)) : null,
            'lot_city' => $a->lot ? collect([$a->lot->city, $a->lot->state])->filter()->implode(', ') : null,
            'lender_slug' => $lender?->slug,
            'lender_rate' => $lender?->rateLabel(),
            'offer' => [
                'rate' => $a->offer_rate_bp ? $lender?->rateLabel($a->offer_rate_bp) : null,
                'months' => $a->offer_tenor_months,
                'disbursed' => $a->disbursed_amount ? $a->money($a->disbursed_amount) : null,
                'disbursed_reference' => $a->disbursed_reference,
                'disbursed_at' => $a->disbursed_at ? self::date($a->disbursed_at) : null,
            ],
            // Where the buyer goes from here: the lender's next steps and how to reach it, once it has said yes.
            'continue' => in_array($a->status, [FinanceStatus::PreApproved, FinanceStatus::Approved, FinanceStatus::Disbursed], true) && $lender ? [
                'steps' => $a->next_steps ?? $lender->next_steps,
                'phone' => PhoneNumber::display($lender->contact_phone) ?: null,
                'email' => $lender->contact_email,
                'website' => $lender->website,
            ] : null,
            'consented_at' => self::date($a->consented_at, 'j M Y, g:ia'),
            // What the buyer agreed to share: only for the lender.
            'applicant' => $side === FinanceMessage::LENDER ? [
                'name' => $a->applicant['name'] ?? null,
                'phone' => PhoneNumber::display($a->applicant['phone'] ?? null) ?: null,
                'email' => $a->applicant['email'] ?? null,
                'monthly_income' => isset($a->applicant['monthly_income']) ? Money::format((int) $a->applicant['monthly_income'] * 100) : null,
                'monthly_commitments' => isset($a->applicant['monthly_commitments']) ? Money::format((int) $a->applicant['monthly_commitments'] * 100) : null,
                'employment' => FinanceApplication::EMPLOYMENT[$a->applicant['employment'] ?? ''] ?? null,
                'employer' => $a->applicant['employer'] ?? null,
            ] : null,
            'messages' => $a->messages->map(fn (FinanceMessage $m) => [
                'ulid' => $m->ulid,
                'side' => $m->side,
                'mine' => $m->side === $side,
                'author' => match ($m->side) {
                    FinanceMessage::BUYER => $side === FinanceMessage::BUYER ? 'You' : ($m->user->name ?? 'Buyer'),
                    FinanceMessage::LENDER => $side === FinanceMessage::LENDER ? ($m->user->name ?? $lender?->name) : $lender?->name,
                    default => 'LotLink',
                },
                'body' => $m->body,
                'file' => $m->attachment_path ? ['name' => $m->attachment_name, 'url' => URL::temporarySignedRoute('finance.file', now()->addMinutes(30), ['message' => $m->ulid])] : null,
                'time' => self::date($m->created_at, 'j M, g:ia'),
            ])->values(),
        ];
    }

    public static function date(?Carbon $at, string $format = 'j M Y'): ?string
    {
        return $at?->copy()->setTimezone(self::TZ)->format($format);
    }
}
