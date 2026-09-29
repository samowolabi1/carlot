<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Actions\SendMessage;
use App\Domain\Leads\Actions\StartConversation;
use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;
use App\Domain\Leads\Policies\ConversationPolicy;
use App\Domain\Leads\Support\LeadPresenter;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** The buyer's chats with lots. Messages go through `SendMessage` (leads, read markers, push, broadcast). */
class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $leads = Lead::withoutGlobalScopes()->where('customer_id', $request->user()->id)
            ->whereHas('conversation')->with(['conversation', 'vehicle.make', 'vehicle.model'])
            ->get()->sortByDesc(fn (Lead $l) => $l->conversation?->last_message_at)->values();
        $lots = Lot::withTrashed()->whereIn('id', $leads->pluck('lot_id'))->get()->keyBy('id');

        return response()->json(['data' => $leads->map(function (Lead $lead) use ($lots) {
            $conversation = $lead->conversation;
            $last = $conversation?->messages()->reorder('id', 'desc')->first();

            return [
                'ulid' => $conversation?->ulid,
                'lot' => ['slug' => $lots[$lead->lot_id]->slug, 'name' => $lots[$lead->lot_id]->name, 'logo_url' => $lots[$lead->lot_id]->logo_url],
                'car' => $lead->vehicle ? ['ulid' => $lead->vehicle->ulid, 'title' => $lead->vehicle->title()] : null,
                'last' => $last ? Str::limit($last->body ?: 'Photo', 90) : null,
                'last_at' => $conversation?->last_message_at?->toIso8601String(),
                'unread' => $conversation?->unreadFor(Message::CUSTOMER) ?? 0,
            ];
        })]);
    }

    /** Start (or reopen) a chat about a car or with a lot. */
    public function store(Request $request, StartConversation $start): JsonResponse
    {
        $data = $request->validate([
            'vehicle' => ['required_without:lot', 'nullable', 'string', 'size:26'],
            'lot' => ['required_without:vehicle', 'nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        $vehicle = filled($data['vehicle'] ?? null) ? Vehicle::query()->marketplace()->where('vehicles.ulid', strtolower($data['vehicle']))->first() : null;
        $lot = $vehicle->lot ?? Lot::where('slug', $data['lot'] ?? '')->where('status', LotStatus::Active)->first();
        abort_if($lot === null, 404);
        abort_if($request->user()->hasLotRole($lot), 403, 'You work at this lot.');

        $conversation = $start->run($request->user(), $lot, $vehicle, $data['body'] ?? null);

        return $this->thread($request, $conversation, 201);
    }

    /** The thread; `?after={message id}` returns only newer messages (for polling). Marks it read. */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeBuyer($request, $conversation);

        return $this->thread($request, $conversation);
    }

    public function reply(Request $request, Conversation $conversation, SendMessage $send): JsonResponse
    {
        $this->authorizeBuyer($request, $conversation);
        $data = $request->validate([
            'body' => ['required_without:photo', 'nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'max:8192'],
        ]);

        $send->run($conversation, $request->user(), Message::CUSTOMER, (string) ($data['body'] ?? ''), $request->file('photo'));

        return $this->thread($request, $conversation, 201);
    }

    private function authorizeBuyer(Request $request, Conversation $conversation): void
    {
        Gate::forUser($request->user())->authorize('view', $conversation);
        abort_unless(app(ConversationPolicy::class)->isCustomer($request->user(), $conversation), 403);
    }

    private function thread(Request $request, Conversation $conversation, int $status = 200): JsonResponse
    {
        $lead = Lead::withoutGlobalScopes()->with(['vehicle.make', 'vehicle.model'])->findOrFail($conversation->lead_id);
        $lot = Lot::withTrashed()->findOrFail($lead->lot_id);
        $conversation->forceFill(['customer_read_at' => now()])->save();
        $after = (int) $request->query('after', 0);

        return response()->json(['data' => [
            'ulid' => $conversation->ulid,
            'lot' => ['slug' => $lot->slug, 'name' => $lot->name, 'logo_url' => $lot->logo_url, 'phone' => $lot->phone],
            'car' => $lead->vehicle && ! $lead->vehicle->trashed() ? ['ulid' => $lead->vehicle->ulid, 'title' => $lead->vehicle->title(), 'price' => $lead->vehicle->formattedPrice()] : null,
            'messages' => array_values(array_filter(LeadPresenter::messages($conversation, $lot->timezone), fn (array $m) => ($m['id'] ?? 0) > $after)),
        ]], $status);
    }
}
