<?php

namespace App\Http\Resources;

use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\LotHour;
use App\Domain\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Lot */
class LotSettingsResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'tagline' => $this->tagline,
            'about' => $this->about,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'email' => $this->email,
            'logo_url' => $this->logo_url,
            'cover_url' => $this->cover_url,
            'brand_color' => $this->brand_color,
            'address' => $this->address,
            'landmark' => $this->landmark,
            'city' => $this->city,
            'state' => $this->state,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'status' => $this->status->value,
            'submitted' => $this->submitted_at !== null,
            'hours' => $this->whenLoaded('hours', fn () => [
                'days' => $this->hours->map(fn (LotHour $hour) => [
                    'weekday' => $hour->weekday,
                    'label' => LotHour::WEEKDAYS[$hour->weekday],
                    'is_closed' => $hour->is_closed,
                    'opens_at' => $hour->opens_at ? substr($hour->opens_at, 0, 5) : null,
                    'closes_at' => $hour->closes_at ? substr($hour->closes_at, 0, 5) : null,
                ])->values(),
                // Booking rules are saved on every day; read them from the first.
                'slot_minutes' => $this->hours->isEmpty() ? 30 : $this->hours->first()->slot_minutes,
                'slot_capacity' => $this->hours->isEmpty() ? 2 : $this->hours->first()->slot_capacity,
            ]),
            'invitations' => $this->whenLoaded('invitations', fn () => $this->invitations->map(fn ($invitation) => [
                'id' => $invitation->id,
                'contact' => $invitation->isEmail() ? $invitation->phone_or_email : PhoneNumber::mask($invitation->phone_or_email),
                'role' => $invitation->role->value,
            ])->values()),
        ];
    }
}
