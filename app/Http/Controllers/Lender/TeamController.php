<?php

namespace App\Http\Controllers\Lender;

use App\Domain\Accounts\Models\User;
use App\Domain\Finance\Actions\AddLenderMember;
use App\Domain\Finance\Enums\FinanceStatus;
use App\Domain\Finance\Enums\LenderRole;
use App\Domain\Finance\Models\Lender;
use App\Domain\Support\Fields;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The lender's team: admins add and remove officers (by email). */
class TeamController extends Controller
{
    public function index(Request $request, Lender $lender): Response
    {
        $roles = $lender->memberships()->pluck('role', 'user_id');

        return Inertia::render('Lender/Team', [
            'members' => $lender->members()->orderBy('name')->get()->map(fn (User $u) => [
                'ulid' => $u->ulid,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $roles[$u->id] ?? null,
                'me' => $u->is($request->user()),
                'open' => $lender->applications()->where('assigned_to', $u->id)->whereIn('status', FinanceStatus::open())->count(),
            ])->values(),
            'roles' => LenderRole::options(),
            'can_manage' => $lender->roleOf($request->user()) === LenderRole::Admin,
        ])->withViewData(['meta' => ['title' => 'Team — '.$lender->name, 'robots' => 'noindex']]);
    }

    public function store(Request $request, Lender $lender, AddLenderMember $add): RedirectResponse
    {
        $this->authorizeAdmin($request, $lender);
        $data = $request->validate(['name' => Fields::personName(), 'email' => Fields::email(), 'role' => ['required', Rule::enum(LenderRole::class)]]);
        $user = $add->run($lender, $data['email'], $data['name'], LenderRole::from($data['role']), $request->user());

        return back()->with('success', "{$user->name} is on the team. We've emailed them how to sign in.");
    }

    public function destroy(Request $request, Lender $lender, string $member, AddLenderMember $add): RedirectResponse
    {
        $this->authorizeAdmin($request, $lender);
        $user = $lender->members()->where('users.ulid', strtolower($member))->firstOrFail();
        $add->remove($lender, $user, $request->user());

        return back()->with('success', "{$user->name} is off the team. Their open applications are back with everyone.");
    }

    private function authorizeAdmin(Request $request, Lender $lender): void
    {
        abort_unless($lender->roleOf($request->user()) === LenderRole::Admin, 403);
    }
}
