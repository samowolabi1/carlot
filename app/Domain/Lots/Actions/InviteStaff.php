<?php

namespace App\Domain\Lots\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotInvitation;
use App\Domain\Lots\Notifications\StaffInvitation;
use App\Domain\Messaging\SmsGateway;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteStaff
{
    public function __construct(private readonly SmsGateway $sms) {}

    /** @param string $contact E.164 phone or email address */
    public function run(Lot $lot, User $inviter, string $contact, LotRole $role): LotInvitation
    {
        if ($role === LotRole::Owner) {
            throw ValidationException::withMessages(['role' => 'Each lot has one owner.']);
        }

        $limit = $lot->plan?->staff_limit;
        $seats = $lot->members()->count() + LotInvitation::withoutGlobalScopes()->where('lot_id', $lot->getKey())->pending()->count();

        if ($limit !== null && $seats >= $limit) {
            throw ValidationException::withMessages([
                'contact' => "Your plan includes {$limit} staff ".Str::plural('seat', $limit).'. Upgrade to invite more.',
            ]);
        }

        $alreadyMember = $lot->members()
            ->where(fn ($q) => $q->where('phone', $contact)->orWhere('email', $contact))
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages(['contact' => 'That person already works at this lot.']);
        }

        // Re-inviting the same contact replaces the old invitation.
        LotInvitation::withoutGlobalScopes()
            ->where('lot_id', $lot->getKey())
            ->where('phone_or_email', $contact)
            ->whereNull('accepted_at')
            ->delete();

        $invitation = LotInvitation::withoutGlobalScopes()->create([
            'lot_id' => $lot->getKey(),
            'phone_or_email' => $contact,
            'role' => $role,
            'token' => Str::random(48),
            'invited_by' => $inviter->getKey(),
            'expires_at' => now()->addDays(config('lotlink.invitation_ttl_days')),
        ]);

        $this->deliver($lot, $invitation);

        return $invitation;
    }

    public function deliver(Lot $lot, LotInvitation $invitation): void
    {
        $url = route('invitations.show', $invitation->token);

        if ($invitation->isEmail()) {
            Notification::route('mail', $invitation->phone_or_email)
                ->notify(new StaffInvitation($lot, $invitation, $url));

            return;
        }

        // WhatsApp delivery arrives with the WhatsApp templates in sprint S4.
        $this->sms->send(
            $invitation->phone_or_email,
            "{$lot->name} has invited you to join their team on LotLink as {$invitation->role->label()}. Accept: {$url}",
        );
    }
}
