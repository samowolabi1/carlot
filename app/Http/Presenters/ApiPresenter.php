<?php

namespace App\Http\Presenters;

use App\Domain\Accounts\Models\User;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Appointments\Support\AppointmentText;
use App\Domain\Legal\Actions\AcceptTerms;
use App\Domain\Legal\LegalDocuments;
use App\Domain\Lots\Models\Lot;

/**
 * Shapes for the mobile API (/api/v1) that the web pages don't already have. Public data reuses
 * `MarketplacePresenter`; nothing here carries internal ids, costs or other dealer-only fields.
 */
final class ApiPresenter
{
    /** @return array<string, mixed> */
    public static function user(User $user): array
    {
        return [
            'ulid' => $user->ulid,
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'role' => $user->role->value,
            'has_password' => $user->password !== null,
            'google_connected' => $user->google_id !== null,
            'terms' => ['accepted' => AcceptTerms::current($user), 'required_version' => LegalDocuments::userVersion(), 'accepted_version' => $user->terms_version],
            'lots' => $user->lots()->orderBy('name')->get()->map(fn (Lot $lot) => [
                'slug' => $lot->slug,
                'name' => $lot->name,
                'role' => $lot->getRelationValue('pivot')?->getAttribute('role'),
                'status' => $lot->status->value,
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    public static function appointment(Appointment $a, Lot $lot): array
    {
        $vehicle = $a->vehicle()->withoutGlobalScopes()->with(['make', 'model', 'cover'])->first();

        return [
            'ulid' => $a->ulid,
            'type' => $a->type->value,
            'type_label' => $a->type->label(),
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'starts_at' => $a->starts_at->toIso8601String(),
            'ends_at' => $a->ends_at->toIso8601String(),
            'when' => AppointmentText::when($a, $lot),
            'upcoming' => $a->isUpcoming(),
            'can_cancel' => $a->isUpcoming(),
            'notes' => $a->notes,
            'car' => $vehicle ? ['ulid' => $vehicle->ulid, 'title' => $vehicle->title(), 'image' => MarketplacePresenter::image($vehicle->cover)] : null,
            'lot' => ['slug' => $lot->slug, 'name' => $lot->name, 'city' => $lot->city, 'directions_url' => $lot->directionsUrl()],
        ];
    }
}
