<?php

namespace App\Domain\Engagement\Actions;

use App\Domain\Accounts\Models\User;
use App\Domain\Analytics\Models\DailyVehicleStat;
use App\Domain\Appointments\Enums\AppointmentStatus;
use App\Domain\Appointments\Models\Appointment;
use App\Domain\Deals\Enums\OfferStatus;
use App\Domain\Deals\Enums\ReservationStatus;
use App\Domain\Deals\Enums\TradeInStatus;
use App\Domain\Deals\Models\Offer;
use App\Domain\Deals\Models\Reservation;
use App\Domain\Deals\Models\TradeIn;
use App\Domain\Engagement\Models\EngagementMessage;
use App\Domain\Engagement\Notifications\EngagementNotice;
use App\Domain\Engagement\Support\EngagementRules;
use App\Domain\Inventory\Enums\VehicleStatus;
use App\Domain\Inventory\Models\Vehicle;
use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Lots\Enums\LotStatus;
use App\Domain\Lots\Models\Lot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The automated emails to lot owners (`EngagementRules`). Runs hourly; each lot is checked at the
 * send hour in its own time zone. A rule reaches a lot's owner at most once per cooldown, and never
 * when there's nothing to say. Admins can preview who would get a rule and send themselves a test.
 */
class RunEngagementRules
{
    /**
     * @param  bool  $anyHour  ignore the send hour (admin "Run now")
     * @return array<string, int> messages sent per rule
     */
    public function run(bool $anyHour = false, ?string $only = null): array
    {
        $settings = EngagementRules::current();
        $sent = array_fill_keys(array_keys(EngagementRules::RULES), 0);

        Lot::query()->where('status', '!=', LotStatus::Suspended)->with('owner')->orderBy('id')
            ->chunk(200, function ($lots) use ($settings, $anyHour, $only, &$sent): void {
                foreach ($lots as $lot) {
                    /** @var Lot $lot */
                    if ($lot->owner === null || (! $anyHour && now($lot->timezone)->hour !== $settings['hour'])) {
                        continue;
                    }
                    foreach ($settings['rules'] as $rule => $config) {
                        if (! $config['enabled'] || ($only !== null && $only !== $rule) || $this->coolingDown($rule, $lot, $config['cooldown'])) {
                            continue;
                        }
                        $found = $this->check($rule, $lot, $config['threshold']);
                        if ($found !== null) {
                            $this->send($rule, $lot, $lot->owner, $found, $config);
                            $sent[$rule]++;
                        }
                    }
                }
            });

        return $sent;
    }

    /**
     * How many lots a rule would reach right now (cooldowns respected), for the admin page.
     */
    public function preview(string $rule): int
    {
        $config = EngagementRules::get($rule);
        $count = 0;
        Lot::query()->where('status', '!=', LotStatus::Suspended)->whereNotNull('owner_id')->orderBy('id')
            ->chunk(200, function ($lots) use ($rule, $config, &$count): void {
                foreach ($lots as $lot) {
                    if (! $this->coolingDown($rule, $lot, $config['cooldown']) && $this->check($rule, $lot, $config['threshold']) !== null) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /** "Send me a test": the rule's email to the admin, filled with a sample lot's figures. */
    public function test(string $rule, User $admin, ?Lot $lot = null): void
    {
        $lot ??= Lot::query()->where('status', LotStatus::Active)->first() ?? Lot::query()->firstOrFail();
        $found = $this->check($rule, $lot, 1) ?? ['count' => 3, 'lines' => ['(Sample) 3 things would be listed here.'], 'url' => route('dealer.dashboard', $lot)];

        $this->send($rule, $lot, $admin, $found, EngagementRules::get($rule), test: true);
    }

    /**
     * What a rule has to say about a lot now, or null if nothing.
     *
     * @return array{count: int, lines: list<string>, url: string}|null
     */
    public function check(string $rule, Lot $lot, int $threshold): ?array
    {
        $now = CarbonImmutable::now();
        $vehicles = fn () => Vehicle::query()->withoutGlobalScopes()->where('lot_id', $lot->id);

        return match ($rule) {
            'inactive_owner' => $this->inactiveOwner($lot, $threshold),
            'no_cars' => $lot->status === LotStatus::Active && $lot->created_at <= $now->subDays($threshold) && ! $vehicles()->exists()
                ? ['count' => 0, 'lines' => [], 'url' => route('dealer.vehicles.create', $lot)] : null,
            'drafts_waiting' => $this->drafts($lot, $threshold),
            'setup_incomplete' => $lot->status === LotStatus::Pending && $lot->submitted_at === null && $lot->created_at <= $now->subDays($threshold)
                ? ['count' => 0, 'lines' => [], 'url' => route('dealer.home')] : null,
            'pending_actions' => $this->pending($lot, $threshold),
            default => null,
        };
    }

    /** @return array{count: int, lines: list<string>, url: string}|null */
    private function inactiveOwner(Lot $lot, int $days): ?array
    {
        $owner = $lot->owner;
        $seen = $owner === null ? null : ($owner->last_seen_at ?? $owner->created_at);
        if ($lot->status !== LotStatus::Active || $seen === null || $seen->gt(now()->subDays($days))) {
            return null;
        }

        $views = (int) DailyVehicleStat::query()->where('lot_id', $lot->id)->where('date', '>=', now($lot->timezone)->subDays(30)->toDateString())->sum('views');
        $leads = Lead::query()->withoutGlobalScopes()->where('lot_id', $lot->id)->where('created_at', '>=', now()->subDays(30))->count();
        $waiting = $this->unanswered($lot, 1);

        return [
            'count' => $leads,
            'lines' => array_values(array_filter([
                $views > 0 ? number_format($views).' '.Str::plural('view', $views).' of your cars' : 'Your cars are still on LotLink',
                $leads > 0 ? $leads.' new '.Str::plural('enquiry', $leads) : null,
                $waiting > 0 ? $waiting.' '.Str::plural('chat', $waiting).' waiting for your reply' : null,
            ])),
            'url' => route('dealer.dashboard', $lot),
        ];
    }

    /** @return array{count: int, lines: list<string>, url: string}|null */
    private function drafts(Lot $lot, int $days): ?array
    {
        if ($lot->status !== LotStatus::Active) {
            return null;
        }
        $drafts = Vehicle::query()->withoutGlobalScopes()->with(['make', 'model'])->where('lot_id', $lot->id)
            ->where('status', VehicleStatus::Draft)->where('updated_at', '<=', now()->subDays($days))->latest('updated_at')->get();
        if ($drafts->isEmpty()) {
            return null;
        }

        return [
            'count' => $drafts->count(),
            'lines' => $drafts->take(5)->map(fn (Vehicle $v) => $v->title())->push(...($drafts->count() > 5 ? ['and '.($drafts->count() - 5).' more'] : []))->values()->all(),
            'url' => route('dealer.vehicles.index', [$lot, 'status' => 'draft']),
        ];
    }

    /** @return array{count: int, lines: list<string>, url: string}|null */
    private function pending(Lot $lot, int $hours): ?array
    {
        if ($lot->status !== LotStatus::Active) {
            return null;
        }
        $before = now()->subHours($hours);
        $scoped = fn (string $model) => $model::query()->withoutGlobalScopes()->where('lot_id', $lot->id)->where('created_at', '<=', $before);

        $items = [
            'chat' => [$this->unanswered($lot, $hours), 'buyer chat not answered', 'buyer chats not answered'],
            'booking' => [$scoped(Appointment::class)->where('status', AppointmentStatus::Pending)->where('starts_at', '>', now())->count(), 'booking to confirm', 'bookings to confirm'],
            'offer' => [$scoped(Offer::class)->where('status', OfferStatus::Pending)->count(), 'offer to answer', 'offers to answer'],
            'reservation' => [$scoped(Reservation::class)->where('status', ReservationStatus::Pending)->count(), 'reservation deposit to check', 'reservation deposits to check'],
            'trade_in' => [$scoped(TradeIn::class)->where('status', TradeInStatus::Submitted)->count(), 'trade-in to value', 'trade-ins to value'],
        ];
        $total = array_sum(array_column($items, 0));
        if ($total === 0) {
            return null;
        }

        return [
            'count' => $total,
            'lines' => array_values(array_map(fn (array $i) => "{$i[0]} ".($i[0] === 1 ? $i[1] : $i[2]), array_filter($items, fn (array $i) => $i[0] > 0))),
            'url' => route('dealer.dashboard', $lot),
        ];
    }

    /** Chats whose latest message the lot hasn't read for at least $hours. */
    private function unanswered(Lot $lot, int $hours): int
    {
        return Conversation::query()
            ->whereIn('lead_id', Lead::query()->withoutGlobalScopes()->where('lot_id', $lot->id)->select('id'))
            ->where('last_message_at', '<=', now()->subHours($hours))
            ->where(fn (Builder $q) => $q->whereNull('lot_read_at')->orWhereColumn('lot_read_at', '<', 'last_message_at'))
            ->count();
    }

    private function coolingDown(string $rule, Lot $lot, int $days): bool
    {
        return EngagementMessage::query()->where('rule', $rule)->where('lot_id', $lot->id)->where('sent_at', '>', now()->subDays($days)->addMinutes(30))->exists();
    }

    /**
     * @param  array{count: int, lines: list<string>, url: string}  $found
     * @param  array{enabled: bool, threshold: int, cooldown: int, subject: string, intro: string}  $config
     */
    private function send(string $rule, Lot $lot, User $to, array $found, array $config, bool $test = false): void
    {
        $values = ['name' => trim(explode(' ', (string) $lot->owner?->name)[0]) ?: 'there', 'lot' => $lot->name, 'count' => $found['count']];
        $message = EngagementMessage::create([
            // Tests don't count towards the lot's cooldown or the rule's stats.
            'rule' => $test ? null : $rule,
            'user_id' => $to->id,
            'lot_id' => $test ? null : $lot->id,
            'url' => $found['url'],
            'sent_at' => now(),
        ]);

        $to->notify(new EngagementNotice(
            $message,
            'nudges',
            ($test ? '[Test] ' : '').EngagementRules::fill($config['subject'], $values),
            [EngagementRules::fill($config['intro'], $values), ...array_map(fn (string $l) => "• {$l}", $found['lines'])],
            EngagementRules::RULES[$rule]['cta'],
            ['mail', 'push'],
        ));
    }
}
