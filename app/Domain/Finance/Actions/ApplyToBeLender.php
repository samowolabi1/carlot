<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminCounters;
use App\Domain\Audit\AuditLog;
use App\Domain\Finance\Enums\LenderIntegration;
use App\Domain\Finance\Enums\LenderRole;
use App\Domain\Finance\Enums\LenderStatus;
use App\Domain\Finance\Models\Lender;
use App\Domain\Legal\Actions\AcceptTerms;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A bank or finance company signs up from the app: the lender (waiting for approval), its licence on the private
 * disk, and the person who applied as its first admin. A CarYard admin approves it (DecideLender) before any buyer
 * sees it.
 */
class ApplyToBeLender
{
    public function __construct(private readonly AcceptTerms $accept) {}

    public const LICENCE_KB = 10240;

    public const LICENCE_MIMES = ['pdf', 'jpg', 'jpeg', 'png'];

    /** @param array<string, mixed> $data see SaveLender::attributes() */
    public function run(User $user, array $data, ?UploadedFile $licence): Lender
    {
        if ($user->isAdmin()) {
            throw ValidationException::withMessages(['name' => 'CarYard admins onboard lenders from the admin panel.']);
        }
        $waiting = Lender::where('status', LenderStatus::Pending)->whereHas('memberships', fn ($q) => $q->where('user_id', $user->id))->exists();
        if ($waiting) {
            throw ValidationException::withMessages(['name' => 'You already have an application waiting for approval.']);
        }

        $lender = DB::transaction(function () use ($user, $data) {
            $lender = Lender::create([
                ...SaveLender::attributes($data),
                'status' => LenderStatus::Pending,
                'integration' => LenderIntegration::Portal,
                'submitted_by' => $user->id,
            ]);
            $lender->members()->attach($user->id, ['role' => LenderRole::Admin->value]);
            AuditLog::record('lender.applied', $lender, ['name' => $lender->name], $user);
            // The sign-up form's "I agree to the Lender Terms" box.
            $this->accept->forLender($lender, $user);

            return $lender;
        });

        if ($licence !== null) {
            $extension = strtolower($licence->guessExtension() ?: $licence->getClientOriginalExtension());
            $lender->update(['licence_path' => (string) $licence->storeAs("lenders/{$lender->ulid}", 'licence-'.Str::random(12).'.'.$extension, ['disk' => Lender::DISK])]);
        }

        AdminCounters::forget();

        return $lender;
    }
}
