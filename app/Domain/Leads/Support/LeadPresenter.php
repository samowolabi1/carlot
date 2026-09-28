<?php

namespace App\Domain\Leads\Support;

use App\Domain\Leads\Models\Conversation;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\Message;
use App\Domain\Support\PhoneNumber;
use Illuminate\Support\Carbon;

/** Shapes leads for the dealer board and lead page. */
final class LeadPresenter
{
    /** @return array<string, mixed> */
    public static function card(Lead $lead, string $tz, int $unread = 0): array
    {
        $name = $lead->customer->name ?? $lead->lotCustomer->name ?? 'Buyer';

        return [
            'ulid' => $lead->ulid,
            'name' => self::shortName($name),
            'car' => $lead->vehicle?->title(),
            'source' => $lead->source->value,
            'source_label' => $lead->source->label(),
            'stage' => $lead->stage->value,
            'assignee' => $lead->assignee?->name ? explode(' ', $lead->assignee->name)[0] : null,
            'age' => self::ago($lead->last_activity_at ?? $lead->created_at),
            'follow_up' => self::followUp($lead->next_follow_up_at, $tz),
            'follow_up_due' => $lead->next_follow_up_at !== null && $lead->next_follow_up_at->lte(now()->setTimezone($tz)->endOfDay()),
            'unread' => $unread,
            'lost_reason' => $lead->lost_reason,
        ];
    }

    /** "Amaka Eze" → "Amaka E." */
    public static function shortName(string $name): string
    {
        // Buyers who haven't given a name are shown by their (formatted) phone number.
        if (preg_match('/^[+\d]/', trim($name))) {
            return trim($name);
        }

        $parts = preg_split('/\s+/', trim($name)) ?: [$name];

        return count($parts) > 1 ? $parts[0].' '.mb_substr((string) end($parts), 0, 1).'.' : $parts[0];
    }

    public static function ago(?Carbon $at): string
    {
        if ($at === null) {
            return '';
        }
        $minutes = (int) $at->diffInMinutes(now());

        return match (true) {
            $minutes < 1 => 'now',
            $minutes < 60 => "{$minutes} min",
            $minutes < 1440 => intdiv($minutes, 60).' h',
            default => intdiv($minutes, 1440).' d',
        };
    }

    private static function followUp(?Carbon $at, string $tz): ?string
    {
        if ($at === null) {
            return null;
        }
        $local = $at->copy()->setTimezone($tz);

        return match (true) {
            $local->isToday() => 'Follow up today',
            $local->isPast() => 'Follow-up overdue',
            $local->isTomorrow() => 'Follow up tomorrow',
            default => 'Follow up '.$local->format('D j M'),
        };
    }

    /**
     * The buyer's number is visible once they have contacted the lot (TDD: Privacy). Every
     * lead source so far is the buyer reaching out; later sources (saved searches) won't be.
     */
    public static function phone(Lead $lead): ?array
    {
        $phone = $lead->customer->phone ?? $lead->lotCustomer?->phone;

        return $phone ? ['e164' => $phone, 'display' => PhoneNumber::display($phone), 'whatsapp' => ltrim($phone, '+')] : null;
    }

    /** @return list<array<string, mixed>> */
    public static function messages(?Conversation $conversation, string $tz): array
    {
        if ($conversation === null) {
            return [];
        }

        return $conversation->messages()->with('sender')->latest('id')->limit(200)->get()->reverse()->values()
            ->map(fn (Message $m) => $m->present($tz))->all();
    }
}
