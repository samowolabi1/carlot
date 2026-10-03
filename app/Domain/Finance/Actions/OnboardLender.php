<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Admin\AdminCounters;
use App\Domain\Audit\AuditLog;
use App\Domain\Finance\Enums\LenderRole;
use App\Domain\Finance\Enums\LenderStatus;
use App\Domain\Finance\Models\Lender;
use App\Domain\Finance\Notifications\LenderAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A CarYard admin sets up a lender directly (a signed partnership): the lender, active at once, and its first admin's
 * account (found or made from their email), who is emailed how to sign in to the lender portal.
 */
class OnboardLender
{
    public function __construct(private readonly SaveLender $save) {}

    /** @param array<string, mixed> $data see SaveLender::attributes(), plus admin_name and admin_email */
    public function run(User $admin, array $data): Lender
    {
        $email = Str::lower(trim((string) ($data['admin_email'] ?? '')));
        if ($email === '') {
            throw ValidationException::withMessages(['admin_email' => 'Add the email address the lender\'s admin will sign in with.']);
        }

        $lender = DB::transaction(function () use ($admin, $data, $email) {
            $member = User::query()->where('email', $email)->first();
            if ($member?->isAdmin()) {
                throw ValidationException::withMessages(['admin_email' => 'That is a CarYard admin account. Use the lender\'s own email.']);
            }
            $member ??= User::query()->create(['name' => trim((string) ($data['admin_name'] ?? $data['contact_name'] ?? '')), 'email' => $email, 'role' => UserRole::Customer]);

            $lender = new Lender(['status' => LenderStatus::Active, 'submitted_by' => $admin->id, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
            $this->save->run($lender->fill(['slug' => SaveLender::slug((string) $data['name'])]), $data);
            $lender->members()->attach($member->id, ['role' => LenderRole::Admin->value]);
            AuditLog::record('admin.lender_onboarded', $lender, ['admin' => $member->ulid], $admin);

            return $lender;
        });

        $lender->members()->first()?->notify(new LenderAlert(
            "{$lender->name} is set up on CarYard. Sign in with this email address to see car loan applications from buyers.",
            route('lender.home'),
        ));
        AdminCounters::forget();

        return $lender;
    }
}
