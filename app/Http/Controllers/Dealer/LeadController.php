<?php

namespace App\Http\Controllers\Dealer;

use App\Domain\Finance\Models\Budget;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Actions\SendMessage;
use App\Domain\Leads\Actions\StartConversation;
use App\Domain\Leads\Actions\UpdateLead;
use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadNote;
use App\Domain\Leads\Models\Message;
use App\Domain\Leads\Support\LeadPresenter;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotBankAccount;
use App\Domain\Lots\Models\LotMember;
use App\Domain\Support\Money;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The lead board (design D5) and lead page with chat (D6), TDD M11. */
class LeadController extends Controller
{
    public function index(Request $request, Lot $lot): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:60'], 'who' => ['nullable', 'in:everyone,mine,unassigned']]);
        $who = $filters['who'] ?? 'everyone';
        $tz = $lot->timezone;

        $leads = Lead::query()->with(['customer', 'lotCustomer', 'vehicle.make', 'vehicle.model', 'assignee', 'conversation'])
            ->where(fn (Builder $q) => $q->whereNotIn('stage', [LeadStage::Won, LeadStage::Lost])
                ->orWhere('closed_at', '>=', now($tz)->startOfMonth()->utc()))
            ->when($who === 'mine', fn (Builder $q) => $q->where('assigned_to', $request->user()->id))
            ->when($who === 'unassigned', fn (Builder $q) => $q->whereNull('assigned_to'))
            ->when($filters['q'] ?? null, function (Builder $q, string $term): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $digits = ltrim((string) preg_replace('/\D/', '', $term), '0');
                $q->where(fn (Builder $q) => $q
                    ->whereHas('customer', fn (Builder $c) => $c->where('name', 'like', $like)->when($digits !== '', fn ($c) => $c->orWhere('phone', 'like', "%{$digits}%")))
                    ->orWhereHas('vehicle', fn (Builder $v) => $v->withoutGlobalScopes()->where('trim', 'like', $like)
                        ->orWhereHas('make', fn ($m) => $m->where('name', 'like', $like))
                        ->orWhereHas('model', fn ($m) => $m->where('name', 'like', $like))));
            })
            ->orderByDesc('last_activity_at')
            ->limit(300)
            ->get();

        $columns = collect(LeadStage::cases())->reject(fn (LeadStage $s) => $s === LeadStage::Lost)->map(fn (LeadStage $stage) => [
            'stage' => $stage->value,
            'label' => $stage->label(),
            'leads' => $leads->filter(fn (Lead $l) => $l->stage === $stage)->values()
                ->map(fn (Lead $l) => LeadPresenter::card($l, $tz, $l->conversation?->unreadFor(Message::LOT) ?? 0)),
        ])->values();

        return Inertia::render('Dealer/Leads/Board', [
            'columns' => $columns,
            'lost' => $leads->filter(fn (Lead $l) => $l->stage === LeadStage::Lost)->count(),
            'filters' => ['q' => $filters['q'] ?? '', 'who' => $who],
        ]);
    }

    public function show(Request $request, Lot $lot, Lead $lead): Response
    {
        $lead->load(['customer.budget', 'lotCustomer', 'vehicle.make', 'vehicle.model', 'vehicle.cover', 'assignee', 'notes.user', 'conversation']);
        $tz = $lot->timezone;

        // Opening the lead reads the chat.
        $lead->conversation?->forceFill(['lot_read_at' => now()])->save();

        $budget = $lead->customer?->budget;

        return Inertia::render('Dealer/Leads/Show', [
            'lead' => [
                ...LeadPresenter::card($lead, $tz),
                'full_name' => $lead->customer->name ?? $lead->lotCustomer?->name,
                'phone' => LeadPresenter::phone($lead),
                'first_contact' => $lead->created_at?->copy()->setTimezone($tz)->format('D j M, H:i'),
                'budget' => $budget instanceof Budget ? 'Up to '.Money::format($budget->max_price) : null,
                'car_url' => $lead->vehicle ? url($lead->vehicle->publicPath()) : null,
                'car_price' => $lead->vehicle?->formattedPrice(),
                'assigned_ulid' => $lead->assignee?->ulid,
                'follow_up_at' => $lead->next_follow_up_at?->copy()->setTimezone($tz)->format('Y-m-d\TH:i'),
                'customer_book' => $lead->lotCustomer ? route('dealer.manager.customers.show', [$lot, $lead->lotCustomer->ulid]) : null,
                'conversation' => $lead->conversation?->ulid,
            ],
            'messages' => LeadPresenter::messages($lead->conversation, $tz),
            'notes' => $lead->notes->map(fn (LeadNote $n) => [
                'body' => $n->body,
                'by' => $n->user?->name ? explode(' ', $n->user->name)[0] : null,
                'at' => $n->created_at?->copy()->setTimezone($tz)->format('D j M, H:i'),
            ]),
            'stages' => LeadStage::options(),
            'staff' => LotMember::query()->where('lot_id', $lot->id)->with('user')->get()
                ->map(fn (LotMember $m) => ['ulid' => $m->user->ulid, 'name' => ($m->user->name ?? $m->user->phone).' ('.$m->role->label().')']),
            'canAssign' => $request->user()->hasLotRole($lot, LotRole::Owner, LotRole::Manager),
            'bookUrl' => route('dealer.calendar', $lot),
        ]);
    }

    public function update(Request $request, Lot $lot, Lead $lead, UpdateLead $update): RedirectResponse
    {
        $data = $request->validate([
            'stage' => ['sometimes', 'nullable', Rule::enum(LeadStage::class)],
            'assigned_to' => ['sometimes', 'nullable', 'string', 'size:26'],
            'next_follow_up_at' => ['sometimes', 'nullable', 'date'],
            'lost_reason' => ['nullable', 'string', 'max:120'],
        ]);

        // Sales reps can take a lead themselves; owners and managers assign anyone.
        if (array_key_exists('assigned_to', $data) && $data['assigned_to'] !== $request->user()->ulid && ! $request->user()->hasLotRole($lot, LotRole::Owner, LotRole::Manager)) {
            abort(403);
        }

        $update->run($lead, $request->user(), $data, $lot->timezone);

        return back()->with('success', 'Lead updated.');
    }

    public function note(Request $request, Lot $lot, Lead $lead): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $lead->notes()->create(['user_id' => $request->user()->id, 'body' => $data['body']]);

        return back()->with('success', 'Note added.');
    }

    /** The lot's reply, or a quick action: send the lot's location, its bank details, or similar cars. */
    public function message(Request $request, Lot $lot, Lead $lead, SendMessage $send): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required_without_all:preset,photo', 'nullable', 'string', 'max:2000'],
            'preset' => ['nullable', 'in:location,similar,bank'],
            'photo' => ['nullable', 'image', 'max:8192'],
        ]);

        $body = match ($data['preset'] ?? null) {
            'location' => $lot->directionsUrl()
                ? "Here's how to find us: {$lot->name}".($lot->address ? ", {$lot->address}" : '').'. Directions: '.$lot->directionsUrl()
                : abort(422, 'Set your lot location in Settings first.'),
            'similar' => $this->similar($lot, $lead),
            // Buyers pay the lot directly (LotLink never takes car payments).
            'bank' => LotBankAccount::preferredFor($lot->id)?->shareText($lot->name)
                ?? throw ValidationException::withMessages(['preset' => 'Add your bank details in Settings first.']),
            default => (string) ($data['body'] ?? ''),
        };

        $conversation = StartConversation::for($lead);
        $send->run($conversation, $request->user(), Message::LOT, $body, $request->file('photo'));

        return back();
    }

    private function similar(Lot $lot, Lead $lead): string
    {
        $vehicle = $lead->vehicle;
        $cars = Vehicle::query()->whereIn('status', ['available'])
            ->when($vehicle, fn (Builder $q) => $q->whereKeyNot($vehicle->id)->where(fn (Builder $q) => $q
                ->where('body_type', $vehicle->body_type)
                ->orWhereBetween('price', [(int) ($vehicle->price * 0.7), (int) ($vehicle->price * 1.3)])))
            ->with(['make', 'model'])->latest('listed_at')->limit(3)->get();

        if ($cars->isEmpty()) {
            throw ValidationException::withMessages(['preset' => 'No similar cars in stock right now.']);
        }

        return "You might also like:\n".$cars->map(fn (Vehicle $v) => "• {$v->title()} — {$v->formattedPrice()}: ".url($v->publicPath()))->implode("\n");
    }

    /** Marks the chat read (the lead page polls this while open). */
    public function read(Lot $lot, Lead $lead): JsonResponse
    {
        Conversation::where('lead_id', $lead->id)->update(['lot_read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
