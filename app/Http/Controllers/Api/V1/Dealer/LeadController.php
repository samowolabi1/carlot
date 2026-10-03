<?php

namespace App\Http\Controllers\Api\V1\Dealer;

use App\Domain\Leads\Actions\SendMessage;
use App\Domain\Leads\Actions\StartConversation;
use App\Domain\Leads\Actions\UpdateLead;
use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;
use App\Domain\Leads\Support\LeadPresenter;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The seller's leads for staff in the app (`lot.member` checks membership and scopes queries to the seller).
 * Buyers' numbers stay masked until they engage, as on the web (`LeadPresenter::phone`).
 */
class LeadController extends Controller
{
    public function index(Request $request, Lot $lot): JsonResponse
    {
        $filters = $request->validate([
            'stage' => ['nullable', Rule::enum(LeadStage::class)],
            'who' => ['nullable', 'in:everyone,mine,unassigned'],
        ]);

        $leads = Lead::query()->with(['customer', 'lotCustomer', 'vehicle.make', 'vehicle.model', 'assignee', 'conversation'])
            ->when($filters['stage'] ?? null, fn (Builder $q, string $stage) => $q->where('stage', $stage),
                fn (Builder $q) => $q->whereNotIn('stage', [LeadStage::Won, LeadStage::Lost]))
            ->when(($filters['who'] ?? null) === 'mine', fn (Builder $q) => $q->where('assigned_to', $request->user()->id))
            ->when(($filters['who'] ?? null) === 'unassigned', fn (Builder $q) => $q->whereNull('assigned_to'))
            ->orderByDesc('last_activity_at')
            ->paginate(50);

        return response()->json([
            'data' => $leads->getCollection()->map(fn (Lead $l) => LeadPresenter::card($l, $lot->timezone, $l->conversation?->unreadFor(Message::LOT) ?? 0))->values(),
            'meta' => ['current_page' => $leads->currentPage(), 'last_page' => $leads->lastPage(), 'total' => $leads->total()],
        ]);
    }

    /** A lead with its chat (`?after={message id}` for only newer messages); reading it marks the chat read. */
    public function show(Request $request, Lot $lot, Lead $lead): JsonResponse
    {
        $lead->load(['customer', 'lotCustomer', 'vehicle.make', 'vehicle.model', 'assignee', 'conversation']);
        $lead->conversation?->forceFill(['lot_read_at' => now()])->save();
        $after = (int) $request->query('after', 0);

        return response()->json(['data' => [
            ...LeadPresenter::card($lead, $lot->timezone),
            'phone' => LeadPresenter::phone($lead),
            'car_ulid' => $lead->vehicle?->ulid,
            'car_price' => $lead->vehicle?->formattedPrice(),
            'follow_up_at' => $lead->next_follow_up_at?->toIso8601String(),
            'assigned_ulid' => $lead->assignee?->ulid,
            'messages' => array_values(array_filter(LeadPresenter::messages($lead->conversation, $lot->timezone), fn (array $m) => $m['id'] > $after)),
        ]]);
    }

    public function update(Request $request, Lot $lot, Lead $lead, UpdateLead $update): JsonResponse
    {
        $data = $request->validate([
            'stage' => ['sometimes', 'nullable', Rule::enum(LeadStage::class)],
            'assigned_to' => ['sometimes', 'nullable', 'string', 'size:26'],
            'next_follow_up_at' => ['sometimes', 'nullable', 'date'],
            'lost_reason' => ['nullable', 'string', 'max:120'],
        ]);

        // Sales reps can take a lead themselves; owners and managers assign anyone (as on the web).
        if (array_key_exists('assigned_to', $data) && $data['assigned_to'] !== $request->user()->ulid && ! $request->user()->hasLotRole($lot, LotRole::Owner, LotRole::Manager)) {
            abort(403);
        }

        $lead = $update->run($lead, $request->user(), $data, $lot->timezone);

        return $this->show($request, $lot, $lead);
    }

    public function message(Request $request, Lot $lot, Lead $lead, SendMessage $send): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required_without:photo', 'nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'max:8192'],
        ]);

        $send->run(StartConversation::for($lead), $request->user(), Message::LOT, (string) ($data['body'] ?? ''), $request->file('photo'));

        return $this->show($request, $lot, $lead->refresh())->setStatusCode(201);
    }
}
