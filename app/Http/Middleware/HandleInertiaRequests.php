<?php

namespace App\Http\Middleware;

use App\Domain\Leads\Enums\LeadStage;
use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;
use App\Domain\Lots\Support\CurrentLot;
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
                    'submitted' => $lot->submitted_at !== null,
                    // Leads badge: new leads plus leads with unread chat messages.
                    'leads_badge' => Lead::query()->where('stage', LeadStage::New)->count()
                        + Conversation::query()->unreadFor(Message::LOT)->whereIn('lead_id', Lead::query()->where('stage', '!=', LeadStage::New)->select('id'))->count(),
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
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
