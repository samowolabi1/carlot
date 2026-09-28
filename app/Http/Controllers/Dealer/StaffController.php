<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Lots\Actions\InviteStaff;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotInvitation;
use App\Domain\Lots\Models\LotMember;
use App\Domain\Support\PhoneNumber;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function index(Request $request, Lot $lot): Response
    {
        $lot->load('plan');

        $members = LotMember::query()
            ->where('lot_id', $lot->getKey())
            ->with('user')
            ->orderByRaw("case role when 'owner' then 0 when 'manager' then 1 else 2 end")
            ->get()
            ->map(fn (LotMember $member) => [
                'ulid' => $member->user->ulid,
                'name' => $member->user->name,
                'phone' => PhoneNumber::mask($member->user->phone),
                'role' => $member->role->value,
                'is_me' => $member->user_id === $request->user()->getKey(),
            ]);

        $invitations = $lot->invitations()->pending()->latest()->get()->map(fn (LotInvitation $invitation) => [
            'id' => $invitation->id,
            'contact' => $invitation->isEmail() ? $invitation->phone_or_email : PhoneNumber::mask($invitation->phone_or_email),
            'role' => $invitation->role->value,
            'channel' => $invitation->isEmail() ? 'email' : 'SMS',
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);

        return Inertia::render('Dealer/Staff', [
            'members' => $members,
            'invitations' => $invitations,
            'seats' => ['used' => $members->count() + $invitations->count(), 'limit' => $lot->plan?->staff_limit, 'plan' => $lot->plan?->name],
            'canManage' => $request->user()->can('manageStaff', $lot),
        ]);
    }

    public function invite(Request $request, Lot $lot, InviteStaff $inviteStaff): RedirectResponse
    {
        Gate::authorize('manageStaff', $lot);

        $data = $request->validate([
            'contact' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(LotRole::class)->only(LotRole::invitable())],
            'onboarding' => ['sometimes', 'boolean'],
        ]);

        $contact = trim($data['contact']);

        if (str_contains($contact, '@')) {
            validator(['contact' => $contact], ['contact' => ['email']])->validate();
            $contact = strtolower($contact);
        } else {
            $contact = PhoneNumber::tryNormalize($contact)
                ?? throw ValidationException::withMessages(['contact' => 'Enter a valid phone number or email address.']);
        }

        $inviteStaff->run($lot, $request->user(), $contact, LotRole::from($data['role']));

        return back()->with('success', 'Invitation sent.');
    }

    public function resend(Lot $lot, LotInvitation $invitation, InviteStaff $inviteStaff): RedirectResponse
    {
        Gate::authorize('manageStaff', $lot);

        $invitation->update(['expires_at' => now()->addDays(config('lotlink.invitation_ttl_days'))]);
        $inviteStaff->deliver($lot, $invitation);

        return back()->with('success', 'Invitation sent again.');
    }

    public function cancel(Lot $lot, LotInvitation $invitation): RedirectResponse
    {
        Gate::authorize('manageStaff', $lot);

        $invitation->delete();

        return back()->with('success', 'Invitation cancelled.');
    }

    public function update(Request $request, Lot $lot, string $user): RedirectResponse
    {
        Gate::authorize('manageStaff', $lot);

        $data = $request->validate(['role' => ['required', Rule::enum(LotRole::class)->only(LotRole::invitable())]]);

        $this->member($lot, $user)->update(['role' => $data['role']]);

        return back()->with('success', 'Role updated.');
    }

    public function destroy(Lot $lot, string $user): RedirectResponse
    {
        Gate::authorize('manageStaff', $lot);

        $this->member($lot, $user)->delete();

        return back()->with('success', 'Removed from your team.');
    }

    /** A non-owner member of this lot, by user ULID. The owner can't be changed or removed here. */
    private function member(Lot $lot, string $ulid): LotMember
    {
        return LotMember::query()
            ->where('lot_id', $lot->getKey())
            ->where('role', '!=', LotRole::Owner->value)
            ->whereHas('user', fn ($q) => $q->where('ulid', $ulid))
            ->firstOrFail();
    }
}
