<?php

namespace App\Http\Controllers\Account;

use App\Domain\Appointments\Models\Appointment;
use App\Domain\LotManager\Models\LotCustomer;
use App\Domain\LotManager\Models\SalesOrder;
use App\Domain\LotManager\Support\OrderLinks;
use App\Domain\Lots\Models\Lot;
use App\Domain\Support\PhoneNumber;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Account (design 21): budget, bookings, saved cars, orders from lots, and dealer links. */
class AccountController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        // Orders a lot recorded for this phone number (Lot Manager links the account by phone).
        $customerIds = LotCustomer::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('user_id', $user->id)->when($user->phone, fn ($q) => $q->orWhere('phone', $user->phone)))
            ->pluck('id');
        $orders = SalesOrder::withoutGlobalScopes()->with(['vehicle.make', 'vehicle.model'])
            ->whereIn('lot_customer_id', $customerIds)
            ->latest()->limit(20)->get();
        $lotNames = Lot::withTrashed()->whereIn('id', $orders->pluck('lot_id'))->pluck('name', 'id');

        return Inertia::render('Account/Index', [
            'profile' => [
                'name' => $user->name,
                'initials' => collect(explode(' ', (string) $user->name))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') ?: '?',
                'phone' => $user->phone ? PhoneNumber::mask($user->phone) : null,
                'email' => $user->email,
            ],
            'budget' => $user->budget ? '₦'.number_format($user->budget->max_price / 100) : null,
            'counts' => [
                'saved' => $user->favourites()->count(),
                'following' => $user->followedLots()->count(),
                'bookings' => Appointment::withoutGlobalScopes()->where('customer_id', $user->id)->active()->where('starts_at', '>', now())->count(),
            ],
            'orders' => $orders->map(fn (SalesOrder $o) => [
                'order_no' => $o->order_no,
                'car' => $o->vehicle?->title(),
                'lot' => $lotNames[$o->lot_id] ?? null,
                'status' => $o->status->label(),
                'balance' => $o->balance > 0 && $o->isOpen() ? $o->money($o->balance) : null,
                'url' => OrderLinks::track($o),
            ]),
            'lots' => $user->lots()->orderBy('name')->get()->map(fn (Lot $lot) => ['name' => $lot->name, 'url' => route('dealer.dashboard', $lot)]),
        ])->withViewData(['meta' => ['title' => 'Account', 'robots' => 'noindex']]);
    }
}
