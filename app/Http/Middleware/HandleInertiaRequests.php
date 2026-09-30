<?php

namespace App\Http\Middleware;

use App\Domain\Admin\Impersonation;
use App\Domain\Deals\Enums\OfferStatus;
use App\Domain\Deals\Enums\ReservationStatus;
use App\Domain\Deals\Enums\TradeInStatus;
use App\Domain\Deals\Models\Offer;
use App\Domain\Deals\Models\Reservation;
use App\Domain\Deals\Models\TradeIn;
use App\Domain\Finance\Models\Lender;
use App\Domain\Helpdesk\Models\SupportTicket;
use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;
use App\Domain\Lots\Support\CurrentLot;
use App\Domain\Support\Regions;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            // States lots can be in (all of Nigeria's), for location forms.
            'regions' => fn () => Regions::options(),
            'auth' => [
                'user' => $user ? [
                    'ulid' => $user->ulid,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'email' => $user->email,
                    'role' => $user->role->value,
                ] : null,
            ],
            // Lots the user works at, for the dealer lot switcher.
            'lots' => fn () => $user
                ? $user->lots()->orderBy('name')->get()->map(fn ($lot) => [
                    'slug' => $lot->slug,
                    'name' => $lot->name,
                    'initials' => $lot->initials(),
                    'role' => $lot->pivot->role->value,
                ])
                : [],
            // Lenders this person works for (the link to the lender portal) and, inside the portal, the one open.
            'lenders' => fn () => $user ? $user->lenders()->orderBy('name')->get(['lenders.id', 'slug', 'name', 'status'])
                ->map(fn (Lender $l) => ['slug' => $l->slug, 'name' => $l->name, 'status' => $l->status->value])->all() : [],
            'currentLender' => fn () => value($request->attributes->get('currentLender')),
            'currentLot' => function () use ($request) {
                $lot = app(CurrentLot::class)->get();

                if (! $lot) {
                    return null;
                }

                $lot->loadMissing('plan');

                return [
                    'slug' => $lot->slug,
                    'name' => $lot->name,
                    'initials' => $lot->initials(),
                    'logo_url' => $lot->logo_url,
                    'status' => $lot->status->value,
                    'status_label' => $lot->status->label(),
                    'plan' => $lot->plan?->name,
                    'role' => $request->user()?->roleIn($lot)?->value,
                    // Car costs and profit: owners and managers on Pro (TDD M19).
                    'can_costs' => (bool) $request->user()?->can('viewCosts', $lot),
                    'submitted' => $lot->submitted_at !== null,
                    // Leads badge: new leads plus leads with unread chat messages.
                    'leads_badge' => Lead::query()->where('stage', LeadStage::New)->count()
                        + Conversation::query()->unreadFor(Message::LOT)->whereIn('lead_id', Lead::query()->where('stage', '!=', LeadStage::New)->select('id'))->count(),
                    // Offers & trade-ins badge: offers, trade-ins and reservation requests waiting for the lot.
                    'deals_badge' => Offer::query()->where('status', OfferStatus::Pending)->count()
                        + TradeIn::query()->where('status', TradeInStatus::Submitted)->count()
                        + Reservation::query()->where('status', ReservationStatus::Pending)->count(),
                    // Support badge: tickets where LotLink replied and the lot hasn't read it yet.
                    'support_badge' => SupportTicket::query()->unreadByLot()->count(),
                ];
            },
            // The buyer's saved maximum price, for "Within budget" tags (whole naira).
            // Notification centre and chat badges.
            'unread' => fn () => $user ? [
                'notifications' => $user->unreadNotifications()->count(),
                'messages' => Conversation::query()->unreadFor(Message::CUSTOMER)
                    ->whereIn('lead_id', Lead::withoutGlobalScopes()->where('customer_id', $user->id)->select('id'))
                    ->count(),
            ] : null,
            'budget' => fn () => $user?->budget ? intdiv($user->budget->max_price, 100) : null,
            'impersonating' => fn () => Impersonation::active(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
