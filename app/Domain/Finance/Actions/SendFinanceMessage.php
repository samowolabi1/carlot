<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Models\FinanceApplication;
use App\Domain\Finance\Models\FinanceMessage;
use App\Domain\Finance\Notifications\FinanceUpdate;
use App\Domain\Finance\Notifications\LenderAlert;
use App\Domain\Support\Name;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A message (and optional document, e.g. a payslip or ID) between the buyer and the lender on one application.
 * Files go on the private disk behind signed links; the seller never sees any of it. The other side is told.
 */
class SendFinanceMessage
{
    public const MAX_KB = 10240;

    public const MIMES = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    public function run(FinanceApplication $application, User $by, string $side, ?string $body, ?UploadedFile $file = null): FinanceMessage
    {
        $body = trim((string) $body);
        if ($body === '' && $file === null) {
            throw ValidationException::withMessages(['body' => 'Write a message or add a document.']);
        }
        if (in_array($application->status, [FinanceStatus::Failed, FinanceStatus::Withdrawn], true)) {
            throw ValidationException::withMessages(['body' => 'This application is closed.']);
        }

        $attachment = [];
        if ($file !== null) {
            $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension());
            $attachment = [
                'attachment_path' => (string) $file->storeAs("finance/{$application->ulid}", Str::random(24).'.'.$extension, ['disk' => FinanceMessage::DISK]),
                'attachment_name' => Str::limit(basename($file->getClientOriginalName()), 120, ''),
            ];
        }

        $message = FinanceMessage::create([
            'finance_application_id' => $application->id,
            'user_id' => $by->id,
            'side' => $side,
            'body' => $body !== '' ? mb_substr($body, 0, 2000) : 'Sent a document.',
            ...$attachment,
        ]);

        $application->forceFill([$side === FinanceMessage::BUYER ? 'buyer_read_at' : 'lender_read_at' => now()])->save();

        $what = $file !== null ? ($body !== '' ? 'a message and a document' : 'a document') : 'a message';
        if ($side === FinanceMessage::BUYER) {
            $team = $application->assignee ? collect([$application->assignee]) : $application->lender->members()->get();
            Notification::send($team, new LenderAlert(
                Name::short($application->user->name)." sent {$what} about the ".($application->vehicle?->title() ?? 'car loan').' application.',
                route('lender.applications.show', [$application->lender, $application]),
            ));
        } else {
            $application->user->notify(new FinanceUpdate($application, $application->lenderName()." sent you {$what} about your car loan application."));
        }

        return $message;
    }
}
