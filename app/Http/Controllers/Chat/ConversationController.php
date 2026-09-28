<?php

namespace App\Http\Controllers\Chat;

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
use App\Http\Presenters\MarketplacePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** The buyer's side of chat (design 15) and the JSON both sides poll when Reverb is off. */
class ConversationController extends Controller
{
    public function index(Request $request): Response
    {
        $leads = Lead::withoutGlobalScopes()->where('customer_id', $request->user()->id)
            ->whereHas('conversation')->with(['conversation', 'vehicle.make', 'vehicle.model'])
            ->get()->sortByDesc(fn (Lead $l) => $l->conversation?->last_message_at)->values();
        $lots = Lot::withTrashed()->whereIn('id', $leads->pluck('lot_id'))->get()->keyBy('id');

        return Inertia::render('Chat/Index', [
            'conversations' => $leads->map(function (Lead $lead) use ($lots) {
                $conversation = $lead->conversation;
                $last = $conversation?->messages()->latest('id')->first();
                $lot = $lots[$lead->lot_id];

                return [
                    'ulid' => $conversation?->ulid,
                    'lot' => $lot->name,
                    'initials' => $lot->initials(),
                    'logo_url' => $lot->logo_url,
                    'car' => $lead->vehicle?->title(),
                    'last' => $last ? Str::limit($last->body ?: 'Photo', 70) : null,
                    'mine' => $last?->side === Message::CUSTOMER,
                    'when' => LeadPresenter::ago($conversation?->last_message_at),
                    'unread' => $conversation?->unreadFor(Message::CUSTOMER) ?? 0,
                ];
            }),
        ])->withViewData(['meta' => ['title' => 'Messages', 'robots' => 'noindex']]);
    }

    /** "Chat" on a car or lot page: opens (or starts) the buyer's conversation with the lot. */
    public function store(Request $request, StartConversation $start): RedirectResponse
    {
        return $this->begin($request, $start);
    }

    /**
     * Guests tapping "Message" are sent through sign-in; the auth middleware brings them
     * back here, where the conversation starts. StartConversation reuses an open lead, so
     * repeat visits land in the same thread.
     */
    public function start(Request $request, StartConversation $start): RedirectResponse
    {
        return $this->begin($request, $start);
    }

    private function begin(Request $request, StartConversation $start): RedirectResponse
    {
        $data = $request->validate([
            'vehicle' => ['required_without:lot', 'nullable', 'string', 'size:26'],
            'lot' => ['required_without:vehicle', 'nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        $vehicle = filled($data['vehicle'] ?? null)
            ? Vehicle::query()->marketplace()->where('vehicles.ulid', strtolower($data['vehicle']))->first()
            : null;
        $lot = $vehicle->lot ?? Lot::where('slug', $data['lot'] ?? '')->where('status', LotStatus::Active)->first();
        abort_if($lot === null, 404);
        abort_if($request->user()->hasLotRole($lot), 403, 'You work at this lot.');

        $conversation = $start->run($request->user(), $lot, $vehicle, $data['body'] ?? null);

        return to_route('conversations.show', $conversation);
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        Gate::authorize('view', $conversation);
        abort_unless(app(ConversationPolicy::class)->isCustomer($request->user(), $conversation), 403);

        $lead = Lead::withoutGlobalScopes()->with(['vehicle.make', 'vehicle.model', 'vehicle.cover', 'vehicle.lot'])->findOrFail($conversation->lead_id);
        $lot = Lot::withTrashed()->findOrFail($lead->lot_id);
        $conversation->forceFill(['customer_read_at' => now()])->save();

        return Inertia::render('Chat/Show', [
            'conversation' => $conversation->ulid,
            'lot' => [
                'name' => $lot->name,
                'initials' => $lot->initials(),
                'logo_url' => $lot->logo_url,
                'phone' => $lot->phone,
                'url' => route('lots.show', $lot->slug),
            ],
            'car' => $lead->vehicle && ! $lead->vehicle->trashed() ? [
                'title' => $lead->vehicle->title(),
                'price' => $lead->vehicle->formattedPrice(),
                'url' => $lead->vehicle->publicPath(),
                'image' => MarketplacePresenter::image($lead->vehicle->cover)['src'] ?? null,
            ] : null,
            'messages' => LeadPresenter::messages($conversation, $lot->timezone),
            'bookUrl' => route('bookings.create', ['lot' => $lot->slug, 'car' => $lead->vehicle?->ulid]),
        ])->withViewData(['meta' => ['title' => "Chat with {$lot->name}", 'robots' => 'noindex']]);
    }

    public function reply(Request $request, Conversation $conversation, SendMessage $send): RedirectResponse|JsonResponse
    {
        Gate::authorize('view', $conversation);
        abort_unless(app(ConversationPolicy::class)->isCustomer($request->user(), $conversation), 403);

        $data = $request->validate([
            'body' => ['required_without:photo', 'nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'max:8192'],
        ]);

        $send->run($conversation, $request->user(), Message::CUSTOMER, (string) ($data['body'] ?? ''), $request->file('photo'));

        return back();
    }

    /** New messages since an id, for either side; also marks them read for the viewer. */
    public function poll(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);
        $after = (int) $request->query('after', 0);
        $lead = Lead::withoutGlobalScopes()->findOrFail($conversation->lead_id);
        $tz = (string) (Lot::withTrashed()->whereKey($lead->lot_id)->value('timezone') ?? config('lotlink.timezone'));

        $messages = $conversation->messages()->with('sender')->where('id', '>', $after)->limit(100)->get();

        $side = $lead->customer_id === $request->user()->id ? 'customer_read_at' : 'lot_read_at';
        if ($messages->isNotEmpty()) {
            $conversation->forceFill([$side => now()])->save();
        }

        return response()->json(['messages' => $messages->map(fn (Message $m) => $m->present($tz))->values()]);
    }
}
