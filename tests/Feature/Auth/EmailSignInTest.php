<?php

use App\Domain\Accounts\Enums\UserRole;
use App\Domain\Accounts\Mail\LoginCode;
use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\Lots\Actions\CreateLot;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Mail::fake());

function emailCode(): string
{
    $code = null;
    Mail::assertSent(LoginCode::class, function (LoginCode $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return (string) $code;
}

it('signs up with an email address and a code, with no phone needed', function () {
    $this->get(route('login', ['method' => 'email']))->assertInertia(fn ($page) => $page->component('Auth/Login')->where('method', 'email'));

    $this->post(route('login.send'), ['method' => 'email', 'email' => '  Ada.Obi@Example.com '])->assertRedirect(route('login.verify'));
    Mail::assertSent(LoginCode::class, fn (LoginCode $mail) => $mail->hasTo('ada.obi@example.com'));

    $this->get(route('login.verify'))->assertInertia(fn ($page) => $page->where('destination', 'a•••i@example.com')->where('method', 'email')->where('channel', 'email'));

    $this->post(route('login.check'), ['code' => emailCode()])->assertRedirect();

    $user = User::sole();
    expect($user)->email->toBe('ada.obi@example.com')->phone->toBeNull()->role->toBe(UserRole::Customer)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($this->whatsapp->sent)->toBe([])->and($this->sms->sent)->toBe([]);
    $this->assertAuthenticatedAs($user);

    // New accounts add their name first, then can start a lot like anyone else.
    $this->get(route('dealer.onboarding.start'))->assertRedirect(route('profile.name'));
});

it('signs back in to the same account by email', function () {
    $existing = User::factory()->create(['email' => 'owner@primemotors.ng', 'phone' => '+2348031112222']);

    $this->post(route('login.send'), ['method' => 'email', 'email' => 'owner@primemotors.ng']);
    $this->post(route('login.check'), ['code' => emailCode()]);

    $this->assertAuthenticatedAs($existing);
    expect(User::count())->toBe(1);
});

it('rejects wrong codes and limits email codes too', function () {
    $this->post(route('login.send'), ['method' => 'email', 'email' => 'not-an-email'])->assertSessionHasErrors('email');

    $this->post(route('login.send'), ['method' => 'email', 'email' => 'ada@example.com']);
    $this->post(route('login.check'), ['code' => '000000'])->assertSessionHasErrors('code');
    $this->assertGuest();

    $this->post(route('login.send'), ['method' => 'email', 'email' => 'ada@example.com']);
    $this->post(route('login.send'), ['method' => 'email', 'email' => 'ada@example.com']);
    $this->post(route('login.send'), ['method' => 'email', 'email' => 'ada@example.com'])->assertSessionHasErrors('email');
});

it('lets a buyer who signed up by email contact a lot: the customer book matches them by email', function () {
    $owner = User::factory()->staff()->create();
    $lot = app(CreateLot::class)->run($owner, ['name' => 'Prime Motors', 'phone' => '+2348021112233']);
    $lot->update(['status' => 'active']);
    $car = Vehicle::factory()->withPhoto()->available()->create(['lot_id' => $lot->id]);
    $buyer = User::factory()->create(['phone' => null, 'email' => 'ada@example.com', 'name' => 'Ada Obi']);

    $this->actingAs($buyer)->post(route('conversations.store'), ['vehicle' => $car->ulid])->assertRedirect();
    $this->actingAs($buyer)->post(route('conversations.store'), ['vehicle' => $car->ulid]);

    $customer = LotCustomer::withoutGlobalScopes()->sole();
    expect($customer)->phone->toBeNull()->email->toBe('ada@example.com')->user_id->toBe($buyer->id);

    $this->actingAs($owner)->get(route('dealer.manager.customers.show', [$lot, $customer]))->assertOk();
    $this->actingAs($buyer)->get(route('account'))->assertOk();
});
