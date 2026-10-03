<?php

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Helpdesk\Actions\ReplyToTicket;
use App\Domain\Helpdesk\Enums\TicketStatus;
use App\Domain\Helpdesk\Models\SupportMessage;
use App\Domain\Helpdesk\Models\SupportTicket;
use App\Domain\Helpdesk\Notifications\SupportTicketAlert;
use App\Domain\Helpdesk\Notifications\SupportTicketUpdate;
use App\Domain\Lots\Models\Lot;
use App\Filament\Resources\SupportTicketResource\Pages\ListSupportTickets;
use App\Filament\Resources\SupportTicketResource\Pages\ViewSupportTicket;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->lot = Lot::factory()->create();
    $this->owner = $this->lot->owner;
    $this->admin = User::factory()->admin()->create(['name' => 'Ada Obi', 'email' => 'ada@lotlink.test']);
});

function openTicket(array $overrides = []): SupportTicket
{
    test()->actingAs(test()->owner)->post(route('dealer.support.store', test()->lot), [
        'subject' => 'Deposit not showing',
        'category' => 'payments',
        'priority' => 'high',
        'body' => 'A buyer paid a reservation deposit yesterday but we cannot see it.',
        ...$overrides,
    ])->assertRedirect();

    return SupportTicket::withoutGlobalScopes()->latest('id')->firstOrFail();
}

it('lets a seller open a ticket with a screenshot and alerts the admins', function () {
    Notification::fake();

    $ticket = openTicket(['attachment' => UploadedFile::fake()->image('screen.png')]);

    expect($ticket)
        ->lot_id->toBe($this->lot->id)
        ->status->toBe(TicketStatus::Open)
        ->reference->toStartWith('T-')
        ->and($ticket->messages)->toHaveCount(1)
        ->and($ticket->messages[0]->attachment_name)->toBe('screen.png');
    Storage::disk('local')->assertExists($ticket->messages[0]->attachment_path);

    Notification::assertSentTo($this->admin, SupportTicketAlert::class, fn ($n) => $n->event === 'opened');
    Notification::assertNotSentTo($this->owner, SupportTicketAlert::class);

    $this->get(route('dealer.support.index', $this->lot))->assertInertia(fn (Assert $page) => $page
        ->component('Dealer/Support/Index')
        ->where('counts.active', 1)
        ->where('tickets.data.0.reference', $ticket->reference)
        ->missing('tickets.data.0.id'));
});

it('validates new tickets', function () {
    $this->actingAs($this->owner)->post(route('dealer.support.store', $this->lot), [
        'subject' => 'Hi', 'category' => 'nonsense', 'body' => 'short', 'attachment' => UploadedFile::fake()->create('virus.exe', 10),
    ])->assertSessionHasErrors(['subject', 'category', 'body', 'attachment']);

    expect(SupportTicket::withoutGlobalScopes()->count())->toBe(0);
});

it('runs the conversation between the seller and CarYard', function () {
    Notification::fake();
    $ticket = openTicket();

    // An admin replies: the ticket waits on the seller, which is told.
    app(ReplyToTicket::class)->fromAdmin($ticket, $this->admin, 'Thanks, we are checking with Paystack.');
    expect($ticket->refresh())->status->toBe(TicketStatus::Pending)->assigned_to->toBe($this->admin->id);
    Notification::assertSentTo($this->owner, SupportTicketUpdate::class);

    // The seller sees the reply signed with the admin's first name, and the badge clears once read.
    $this->get(route('dealer.support.index', $this->lot))->assertInertia(fn (Assert $page) => $page->where('tickets.data.0.unread', true)->where('currentLot.support_badge', 1));
    $this->get(route('dealer.support.show', [$this->lot, $ticket]))->assertInertia(fn (Assert $page) => $page
        ->component('Dealer/Support/Show')
        ->where('messages.1.author', 'Ada, CarYard Support')
        ->where('messages.1.mine', false));
    $this->get(route('dealer.support.index', $this->lot))->assertInertia(fn (Assert $page) => $page->where('currentLot.support_badge', 0));

    // The seller answers: back with CarYard, and the assigned admin is alerted.
    $this->post(route('dealer.support.reply', [$this->lot, $ticket]), ['body' => 'Receipt attached.', 'attachment' => UploadedFile::fake()->create('receipt.pdf', 50, 'application/pdf')])
        ->assertSessionHasNoErrors();
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    Notification::assertSentTo($this->admin, SupportTicketAlert::class, fn ($n) => $n->event === 'replied');

    // The seller marks it solved, then reopens it.
    $this->patch(route('dealer.support.status', [$this->lot, $ticket]), ['status' => 'resolved'])->assertSessionHasNoErrors();
    expect($ticket->refresh())->status->toBe(TicketStatus::Resolved)->resolved_at->not->toBeNull();
    $this->patch(route('dealer.support.status', [$this->lot, $ticket]), ['status' => 'open'])->assertSessionHasNoErrors();
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open)
        ->and(AuditLog::where('action', 'support.status_changed')->count())->toBe(2);
});

it('never shows internal notes to the seller', function () {
    $ticket = openTicket();
    app(ReplyToTicket::class)->fromAdmin($ticket, $this->admin, 'Lot has 3 chargebacks, be careful.', internal: true);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
    $this->get(route('dealer.support.show', [$this->lot, $ticket]))->assertInertia(fn (Assert $page) => $page->has('messages', 1))
        ->assertDontSee('chargebacks');
});

it('does not take replies on closed tickets', function () {
    $ticket = openTicket();
    $ticket->update(['status' => TicketStatus::Closed]);

    $this->post(route('dealer.support.reply', [$this->lot, $ticket]), ['body' => 'Hello?'])->assertSessionHasErrors('body');
    $this->patch(route('dealer.support.status', [$this->lot, $ticket]), ['status' => 'open'])->assertSessionHasErrors('status');
});

it('keeps tickets and attachments inside the seller', function () {
    $ticket = openTicket(['attachment' => UploadedFile::fake()->image('screen.png')]);
    $other = Lot::factory()->create();
    $stranger = $other->owner;

    $this->actingAs($stranger)->get(route('dealer.support.show', [$this->lot, $ticket]))->assertForbidden();
    $this->actingAs($stranger)->get(route('dealer.support.show', [$other, $ticket]))->assertNotFound();
    $this->actingAs($stranger)->post(route('dealer.support.reply', [$other, $ticket]), ['body' => 'Hi'])->assertNotFound();
    $this->actingAs($stranger)->get(route('dealer.support.index', $other))->assertInertia(fn (Assert $page) => $page->where('counts.active', 0));

    $url = $ticket->messages[0]->attachmentUrl();
    $this->actingAs($stranger)->get($url)->assertNotFound();
    $this->actingAs($this->owner)->get($url)->assertOk();
    $this->actingAs($this->admin)->get($url)->assertOk();
    $this->actingAs($this->owner)->get(route('support.attachment', $ticket->messages[0]->ulid))->assertForbidden(); // unsigned
});

it('lets admins work the queue in /admin', function () {
    Notification::fake();
    $ticket = openTicket();
    $this->actingAs($this->admin);

    $this->get('/admin/support-tickets')->assertOk()->assertSee('Deposit not showing');
    $this->actingAs($this->owner)->get('/admin/support-tickets')->assertForbidden();
    $this->actingAs($this->admin);

    Livewire::test(ListSupportTickets::class)->assertCanSeeTableRecords([$ticket]);

    Livewire::test(ViewSupportTicket::class, ['record' => $ticket->ulid])
        ->assertSee('A buyer paid a reservation deposit')
        ->callAction('note', ['body' => 'Checked Paystack dashboard.'])
        ->callAction('reply', ['body' => 'Found it, payout is on the way.', 'then' => 'resolved'])
        ->assertHasNoActionErrors();

    expect($ticket->refresh())
        ->status->toBe(TicketStatus::Resolved)
        ->admin_read_at->not->toBeNull()
        ->and(SupportMessage::where('support_ticket_id', $ticket->id)->where('internal', true)->count())->toBe(1);
    Notification::assertSentTo($this->owner, SupportTicketUpdate::class);

    Livewire::test(ViewSupportTicket::class, ['record' => $ticket->ulid])
        ->callAction('update', ['status' => 'closed', 'priority' => 'urgent', 'assigned_to' => null])
        ->assertHasNoActionErrors();

    expect($ticket->refresh())->status->toBe(TicketStatus::Closed)->assigned_to->toBeNull()
        ->and(AuditLog::where('action', 'support.priority_changed')->exists())->toBeTrue();
});
