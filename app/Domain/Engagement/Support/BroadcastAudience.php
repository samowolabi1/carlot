<?php

namespace App\Domain\Engagement\Support;

use App\Domain\Accounts\Models\User;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Lots\Enums\LotRole;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use App\Domain\Lots\Models\Plan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who a broadcast reaches: lots matching the filters (state, plan, status, verified, activity), then
 * their owners and, if chosen, managers. One message per person even if they're at several lots.
 */
final class BroadcastAudience
{
    public const ACTIVITY = [
        'inactive' => 'Owner hasn\'t signed in for 30 days',
        'no_live_cars' => 'No cars live',
        'has_live_cars' => 'Has cars live',
    ];

    /**
     * @param  array{states?: list<string>, plans?: list<int|string>, status?: string|null, verified?: string|null, activity?: string|null, managers?: bool}  $audience
     * @return Builder<Lot>
     */
    public static function lots(array $audience): Builder
    {
        $live = fn (Builder $q) => $q->withoutGlobalScopes()->whereIn('status', VehicleStatus::live())->whereNull('held_at');

        return Lot::query()
            ->where('status', '!=', LotStatus::Suspended)
            ->when(($audience['status'] ?? null) && $audience['status'] !== 'any', fn (Builder $q) => $q->where('status', $audience['status']))
            ->when($audience['states'] ?? [], fn (Builder $q, array $states) => $q->whereIn('state', $states))
            ->when($audience['plans'] ?? [], fn (Builder $q, array $plans) => $q->whereIn('plan_id', array_map('intval', $plans)))
            ->when(($audience['verified'] ?? null) === 'yes', fn (Builder $q) => $q->whereNotNull('verified_at'))
            ->when(($audience['verified'] ?? null) === 'no', fn (Builder $q) => $q->whereNull('verified_at'))
            ->when(($audience['activity'] ?? null) === 'no_live_cars', fn (Builder $q) => $q->whereDoesntHave('vehicles', $live))
            ->when(($audience['activity'] ?? null) === 'has_live_cars', fn (Builder $q) => $q->whereHas('vehicles', $live))
            ->when(($audience['activity'] ?? null) === 'inactive', fn (Builder $q) => $q->whereHas('owner', fn (Builder $o) => $o
                ->where(fn (Builder $o) => $o->where('last_seen_at', '<', now()->subDays(30))
                    ->orWhere(fn (Builder $o) => $o->whereNull('last_seen_at')->where('created_at', '<', now()->subDays(30))))));
    }

    /**
     * @param  array{states?: list<string>, plans?: list<int|string>, status?: string|null, verified?: string|null, activity?: string|null, managers?: bool}  $audience
     * @return Collection<int, array{user: User, lot: Lot}> keyed by user id
     */
    public static function recipients(array $audience): Collection
    {
        $roles = ($audience['managers'] ?? false) ? [LotRole::Owner->value, LotRole::Manager->value] : [LotRole::Owner->value];
        $out = collect();

        self::lots($audience)->with(['members' => fn ($q) => $q->wherePivotIn('role', $roles)])->orderBy('id')
            ->chunk(200, function (Collection $lots) use ($out): void {
                foreach ($lots as $lot) {
                    foreach ($lot->members as $user) {
                        if (! $out->has($user->id)) {
                            $out->put($user->id, ['user' => $user, 'lot' => $lot]);
                        }
                    }
                }
            });

        return $out;
    }

    /** "Lagos, Abuja · Starter · active · no cars live · owners" for the admin list. */
    public static function describe(array $audience): string
    {
        $parts = array_filter([
            ($audience['states'] ?? []) ? implode(', ', array_slice($audience['states'], 0, 3)).(count($audience['states']) > 3 ? ' +'.(count($audience['states']) - 3) : '') : 'All states',
            ($audience['plans'] ?? []) ? Plan::whereIn('id', $audience['plans'])->pluck('name')->implode(', ') : null,
            ($audience['status'] ?? 'any') !== 'any' ? (string) $audience['status'] : null,
            match ($audience['verified'] ?? null) {
                'yes' => 'verified', 'no' => 'not verified', default => null
            },
            isset($audience['activity']) ? (self::ACTIVITY[$audience['activity']] ?? null) : null,
            ($audience['managers'] ?? false) ? 'owners and managers' : 'owners',
        ]);

        return implode(' · ', $parts);
    }
}
