<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Helpdesk\Actions\ChangeTicketStatus;
use App\Domain\Helpdesk\Actions\OpenTicket;
use App\Domain\Helpdesk\Actions\ReplyToTicket;
use App\Domain\Helpdesk\Actions\TicketAttachment;
use App\Domain\Helpdesk\Enums\TicketCategory;
use App\Domain\Helpdesk\Enums\TicketPriority;
use App\Domain\Helpdesk\Enums\TicketStatus;
use App\Domain\Helpdesk\Models\SupportMessage;
use App\Domain\Helpdesk\Models\SupportTicket;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Support desk for lots: open tickets with CarYard, message the team, mark them solved. Any team member. */
class SupportController extends Controller
{
    public function index(Request $request, Lot $lot): Response
    {
        Gate::authorize('view', $lot);

        $filter = $request->query('show') === 'done' ? 'done' : 'active';
        $tickets = SupportTicket::query()
            ->when($filter === 'active', fn ($q) => $q->active(), fn ($q) => $q->whereIn('status', [TicketStatus::Resolved, TicketStatus::Closed]))
            ->with('opener')
            ->withCount('publicMessages')
            ->orderByDesc('last_message_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Dealer/Support/Index', [
            'filter' => $filter,
            'counts' => [
                'active' => SupportTicket::query()->active()->count(),
                'waiting' => SupportTicket::query()->where('status', TicketStatus::Pending)->count(),
                'done' => SupportTicket::query()->whereIn('status', [TicketStatus::Resolved, TicketStatus::Closed])->count(),
            ],
            'tickets' => $tickets->through(fn (SupportTicket $t) => self::summary($t, $lot)),
            'form' => self::formOptions(),
        ]);
    }

    public function store(Request $request, Lot $lot, OpenTicket $open): RedirectResponse
    {
        Gate::authorize('view', $lot);

        $data = $request->validate([
            'subject' => ['required', 'string', 'min:4', 'max:160'],
            'category' => ['required', Rule::enum(TicketCategory::class)],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            'vehicle' => ['nullable', 'string', 'size:26'],
            'attachment' => self::attachmentRules(),
        ], [
            'body.min' => 'Tell us a little more so we can help (at least 10 characters).',
            'attachment.mimes' => 'Attach a photo, screenshot or PDF.',
        ]);

        $ticket = $open->run($lot, $request->user(), $data, $request->file('attachment'));

        return redirect()->route('dealer.support.show', [$lot, $ticket])
            ->with('success', "Ticket {$ticket->reference} sent. We usually reply within one working day.");
    }

    public function show(Lot $lot, SupportTicket $supportTicket): Response
    {
        Gate::authorize('view', $lot);
        $ticket = $supportTicket->load(['opener', 'vehicle.make', 'vehicle.model', 'publicMessages.author']);

        if ($ticket->lot_read_at === null) {
            $ticket->forceFill(['lot_read_at' => now()])->save();
        }

        return Inertia::render('Dealer/Support/Show', [
            'ticket' => [
                ...self::summary($ticket, $lot),
                'car' => $ticket->vehicle ? ['title' => $ticket->vehicle->title(), 'url' => route('dealer.vehicles.edit', [$lot, $ticket->vehicle, 'details'])] : null,
                'can_reply' => $ticket->status !== TicketStatus::Closed,
                'can_resolve' => $ticket->status->isActive(),
                'can_reopen' => $ticket->status === TicketStatus::Resolved,
            ],
            'messages' => $ticket->publicMessages->map(fn (SupportMessage $m) => [
                'ulid' => $m->ulid,
                'mine' => ! $m->from_admin,
                'author' => $m->from_admin ? self::supportName($m) : ($m->author->name ?? 'Your team'),
                'body' => $m->body,
                'when' => $m->created_at->copy()->setTimezone($lot->timezone)->format('j M Y, g:ia'),
                'attachment' => $m->attachment_path ? ['name' => $m->attachment_name ?? 'Attachment', 'url' => $m->attachmentUrl()] : null,
            ]),
            'maxMb' => intdiv(TicketAttachment::MAX_KB, 1024),
        ]);
    }

    public function reply(Request $request, Lot $lot, SupportTicket $supportTicket, ReplyToTicket $reply): RedirectResponse
    {
        Gate::authorize('view', $lot);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'attachment' => self::attachmentRules(),
        ], ['attachment.mimes' => 'Attach a photo, screenshot or PDF.']);

        $reply->fromLot($supportTicket, $request->user(), $data['body'], $request->file('attachment'));

        return back()->with('success', 'Message sent to CarYard Support.');
    }

    public function status(Request $request, Lot $lot, SupportTicket $supportTicket, ChangeTicketStatus $change): RedirectResponse
    {
        Gate::authorize('view', $lot);
        $data = $request->validate(['status' => ['required', Rule::in([TicketStatus::Resolved->value, TicketStatus::Open->value])]]);

        $change->byLot($supportTicket, $request->user(), TicketStatus::from($data['status']));

        return back()->with('success', $data['status'] === TicketStatus::Resolved->value ? 'Marked as solved. Thanks for letting us know.' : 'Ticket reopened. We\'ll take another look.');
    }

    /** @return list<string> */
    private static function attachmentRules(): array
    {
        return ['nullable', 'file', 'max:'.TicketAttachment::MAX_KB, 'mimes:'.implode(',', TicketAttachment::MIMES)];
    }

    /** Admins sign with their first name, e.g. "Ada, CarYard Support". */
    private static function supportName(SupportMessage $m): string
    {
        $first = trim((string) strtok((string) ($m->author->name ?? ''), ' '));

        return $first !== '' ? "{$first}, CarYard Support" : 'CarYard Support';
    }

    /** @return array<string, mixed> */
    private static function summary(SupportTicket $t, Lot $lot): array
    {
        return [
            'ulid' => $t->ulid,
            'reference' => $t->reference,
            'subject' => $t->subject,
            'category' => $t->category->label(),
            'priority' => $t->priority->value,
            'priority_label' => $t->priority->short(),
            'status' => $t->status->value,
            'status_label' => $t->status->lotLabel(),
            'unread' => $t->isUnreadByLot(),
            'opened_by' => $t->opener->name ?? null,
            'messages' => $t->public_messages_count ?? null,
            'opened' => $t->created_at->copy()->setTimezone($lot->timezone)->format('j M Y'),
            'updated' => $t->last_message_at?->diffForHumans(),
        ];
    }

    /** @return array<string, mixed> */
    private static function formOptions(): array
    {
        return [
            'categories' => TicketCategory::options(),
            'priorities' => TicketPriority::options(),
            'cars' => Vehicle::query()->with(['make', 'model'])->latest('updated_at')->limit(100)->get()
                ->map(fn (Vehicle $v) => ['value' => $v->ulid, 'label' => $v->title()]),
            'max_mb' => intdiv(TicketAttachment::MAX_KB, 1024),
        ];
    }
}
