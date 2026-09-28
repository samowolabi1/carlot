<?php

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Models\User;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotInvitation;
use App\Domain\Lots\Models\Plan;
use App\Domain\Lots\Notifications\StaffInvitation;
use Illuminate\Support\Facades\Notification;

function invite(Lot $lot, string $contact, string $role = 'sales')
{
    return test()->actingAs($lot->owner)->post(route('dealer.staff.invite', $lot), ['contact' => $contact, 'role' => $role]);
}

beforeEach(function () {
    $this->lot = Lot::factory()->create(['plan_id' => Plan::where('code', 'starter')->value('id')]);
});

it('invites by phone with a WhatsApp link', function () {
    invite($this->lot, '0803 555 1234')->assertSessionHasNoErrors();

    $invitation = LotInvitation::withoutGlobalScopes()->sole();
    expect($invitation->phone_or_email)->toBe('+2348035551234')
        ->and($invitation->role)->toBe(LotRole::Sales)
        ->and($invitation->expires_at->isSameDay(now()->addDays(7)))->toBeTrue()
        ->and($this->whatsapp->to('+2348035551234', 'staff_invitation')[0]->text)->toContain(route('invitations.show', $invitation->token))
        ->and($this->whatsapp->to('+2348035551234', 'staff_invitation')[0]->buttonSuffix)->toBe('invitations/'.$invitation->token);
});

it('invites by email', function () {
    Notification::fake();

    invite($this->lot, 'Kemi@Example.com', 'manager')->assertSessionHasNoErrors();

    expect(LotInvitation::withoutGlobalScopes()->sole()->phone_or_email)->toBe('kemi@example.com');
    Notification::assertSentOnDemand(StaffInvitation::class);
});

it('cannot invite a second owner', function () {
    invite($this->lot, '08035551234', 'owner')->assertSessionHasErrors('role');
});

it('enforces the plan staff limit', function () {
    // Starter allows 3 seats; the owner holds one.
    invite($this->lot, '08035551231')->assertSessionHasNoErrors();
    invite($this->lot, '08035551232')->assertSessionHasNoErrors();
    invite($this->lot, '08035551233')->assertSessionHasErrors('contact');
});

it('only lets the owner invite', function () {
    $manager = User::factory()->staff()->create();
    $this->lot->members()->attach($manager, ['role' => 'manager', 'accepted_at' => now()]);

    $this->actingAs($manager)->post(route('dealer.staff.invite', $this->lot), ['contact' => '08035551234', 'role' => 'sales'])->assertForbidden();
});

it('adds the invited person to the lot when they accept', function () {
    invite($this->lot, '08035551234', 'manager');
    $invitation = LotInvitation::withoutGlobalScopes()->sole();
    $user = User::factory()->create(['phone' => '+2348035551234']);

    $this->actingAs($user)->post(route('invitations.accept', $invitation->token))
        ->assertRedirect(route('dealer.dashboard', $this->lot));

    expect($user->roleIn($this->lot))->toBe(LotRole::Manager)
        ->and($user->fresh()->role)->toBe(UserRole::Staff)
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();
});

it('refuses an invitation meant for someone else', function () {
    invite($this->lot, '08035551234');
    $invitation = LotInvitation::withoutGlobalScopes()->sole();
    $stranger = User::factory()->create(['phone' => '+2348039999999']);

    $this->actingAs($stranger)->post(route('invitations.accept', $invitation->token))->assertSessionHasErrors('invitation');
    expect($stranger->roleIn($this->lot))->toBeNull();
});

it('refuses an expired invitation', function () {
    invite($this->lot, '08035551234');
    $invitation = LotInvitation::withoutGlobalScopes()->sole();
    $user = User::factory()->create(['phone' => '+2348035551234']);

    $this->travel(8)->days();

    $this->actingAs($user)->post(route('invitations.accept', $invitation->token))->assertSessionHasErrors('invitation');
});

it('lets the owner change roles and remove staff, but not themselves', function () {
    $sales = User::factory()->staff()->create();
    $this->lot->members()->attach($sales, ['role' => 'sales', 'accepted_at' => now()]);

    $this->actingAs($this->lot->owner)->patch(route('dealer.staff.update', [$this->lot, $sales->ulid]), ['role' => 'manager']);
    expect($sales->roleIn($this->lot))->toBe(LotRole::Manager);

    $this->actingAs($this->lot->owner)->delete(route('dealer.staff.destroy', [$this->lot, $this->lot->owner->ulid]))->assertNotFound();

    $this->actingAs($this->lot->owner)->delete(route('dealer.staff.destroy', [$this->lot, $sales->ulid]));
    expect($sales->roleIn($this->lot))->toBeNull();
});
