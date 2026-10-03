<?php

namespace App\Domain\Messaging;

use App\Domain\Accounts\Models\User;
use App\Domain\Audit\AuditLog;
use App\Domain\Messaging\Models\MessageTemplate;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Every business-initiated WhatsApp/SMS message the app sends, and the admin's settings for each
 * (/admin → Message templates). The code still builds each `Message`; `Messenger` passes it through
 * `apply()`, which can point WhatsApp at a newly approved template or language, reword the SMS
 * with placeholders ({1}, {2}… are the template's variables in order, {link} the button's URL),
 * or switch a message off. Sign-in codes and staff invitations can't be switched off.
 */
final class MessageCatalogue
{
    private const CACHE = 'lotlink.message_templates';

    /**
     * @var array<string, array{label: string, category: string, variables: list<string>, link: string|null, required?: bool}>
     */
    public const TEMPLATES = [
        'login_code' => ['label' => 'Sign-in code', 'category' => 'Authentication', 'variables' => ['code'], 'link' => null, 'required' => true],
        'staff_invitation' => ['label' => 'Staff invitation', 'category' => 'Utility', 'variables' => ['seller name', 'role'], 'link' => 'invitation link', 'required' => true],
        'lot_welcome' => ['label' => 'Welcome to a seller signed up by an admin', 'category' => 'Utility', 'variables' => ['owner name', 'seller name'], 'link' => 'sign in'],
        'booking_confirmed' => ['label' => 'Booking confirmed', 'category' => 'Utility', 'variables' => ['name', 'what', 'lot', 'when'], 'link' => 'manage booking'],
        'booking_pending' => ['label' => 'Booking waiting for the seller', 'category' => 'Utility', 'variables' => ['name', 'what', 'lot', 'when'], 'link' => 'manage booking'],
        'appointment_reminder' => ['label' => 'Visit reminder', 'category' => 'Utility', 'variables' => ['what', 'lot', 'when', 'directions'], 'link' => 'manage booking'],
        'appointment_update' => ['label' => 'Visit changed, cancelled or refunded', 'category' => 'Utility', 'variables' => ['what', 'lot', 'change'], 'link' => 'manage booking'],
        'dealer_booking_alert' => ['label' => 'New booking (to the seller)', 'category' => 'Utility', 'variables' => ['event', 'buyer', 'what', 'when'], 'link' => 'open calendar'],
        'payment_receipt' => ['label' => 'Payment receipt', 'category' => 'Utility', 'variables' => ['name', 'amount', 'lot', 'car', 'receipt no', 'balance'], 'link' => 'track order'],
        'order_update' => ['label' => 'Order update', 'category' => 'Utility', 'variables' => ['name', 'car', 'lot', 'update'], 'link' => 'track order'],
        'billing_update' => ['label' => 'Billing notice (to the seller)', 'category' => 'Utility', 'variables' => ['lot', 'message'], 'link' => 'open billing'],
        'lot_new_stock' => ['label' => 'New cars at a followed seller', 'category' => 'Marketing', 'variables' => ['lot', 'number of cars', 'example car'], 'link' => 'open the seller page'],
        'follow_up_due' => ['label' => 'Follow-up due (to staff)', 'category' => 'Utility', 'variables' => ['staff name', 'type', 'customer', 'lot'], 'link' => 'open Today / the lead'],
        'new_message' => ['label' => 'Unread chat messages', 'category' => 'Utility', 'variables' => ['sender', 'message snippet'], 'link' => 'open the chat'],
        'offer_update' => ['label' => 'Offer update', 'category' => 'Utility', 'variables' => ['name', 'car', 'lot', 'update'], 'link' => 'open bookings and offers'],
        'trade_in_update' => ['label' => 'Trade-in update', 'category' => 'Utility', 'variables' => ['name', 'car', 'lot', 'update'], 'link' => 'open bookings and offers'],
        'reservation_update' => ['label' => 'Reservation update', 'category' => 'Utility', 'variables' => ['name', 'car', 'lot', 'update'], 'link' => 'open bookings and offers'],
        'instalment_reminder' => ['label' => 'Instalment reminder', 'category' => 'Utility', 'variables' => ['name', 'amount', 'car', 'lot', 'due date'], 'link' => 'track order'],
        'daily_summary' => ['label' => 'Daily summary (to the seller)', 'category' => 'Utility', 'variables' => ['lot', 'date', 'walk-ins', 'new orders', 'money received', 'balances due', 'overdue instalments'], 'link' => 'open the report'],
        'review_invite' => ['label' => 'Review invitation', 'category' => 'Utility', 'variables' => ['lot', 'what (visit type and car)'], 'link' => 'leave a review'],
        'saved_search_match' => ['label' => 'Saved search match', 'category' => 'Marketing', 'variables' => ['search name', 'car', 'price', 'lot'], 'link' => 'see the car'],
        'lot_announcement' => ['label' => 'CarYard announcement (to sellers)', 'category' => 'Marketing', 'variables' => ['owner name', 'title'], 'link' => 'read more'],
        'price_drop' => ['label' => 'Price drop on a saved car', 'category' => 'Marketing', 'variables' => ['car', 'new price', 'amount off', 'lot'], 'link' => 'see the car'],
    ];

    /** Adds a row for every template that doesn't have one yet (the admin list shows them all). */
    public static function sync(): void
    {
        $existing = MessageTemplate::query()->pluck('key')->all();

        foreach (array_diff(array_keys(self::TEMPLATES), $existing) as $key) {
            MessageTemplate::query()->create(['key' => $key, 'whatsapp_template' => $key, 'language' => (string) config('services.whatsapp.language', 'en'), 'enabled' => true]);
        }

        Cache::forget(self::CACHE);
    }

    /** The message as the admin wants it sent, or null when it has been switched off. */
    public static function apply(Message $message): ?Message
    {
        $row = self::settings()[$message->template] ?? null;

        if ($row === null) {
            return $message;
        }

        if (! $row['enabled'] && ! self::required($message->template)) {
            return null;
        }

        $text = $row['sms_text'] !== null && trim($row['sms_text']) !== '' ? self::render($row['sms_text'], $message) : $message->text;

        return $message->with($row['whatsapp_template'] ?: $message->template, $row['language'] ?: null, $text);
    }

    /** Fills {1}, {2}… with the template variables and {link} with the button's URL. */
    public static function render(string $sms, Message $message): string
    {
        $replace = ['{link}' => (string) $message->link()];
        foreach ($message->params as $i => $value) {
            $replace['{'.($i + 1).'}'] = $value;
        }

        return trim((string) preg_replace('/[ \t]{2,}/', ' ', strtr($sms, $replace)));
    }

    /**
     * Problems with an SMS wording, for the admin form: unknown placeholders, or a missing code or link.
     *
     * @return list<string>
     */
    public static function problems(string $key, ?string $sms): array
    {
        if ($sms === null || trim($sms) === '') {
            return [];
        }

        $template = self::TEMPLATES[$key] ?? null;
        $count = count($template['variables'] ?? []);
        $allowed = array_merge(array_map(fn (int $n) => "{{$n}}", range(1, max($count, 1))), ($template['link'] ?? null) ? ['{link}'] : []);
        preg_match_all('/\{[^}]*\}/', $sms, $found);

        $problems = [];
        foreach (array_unique($found[0]) as $placeholder) {
            if (! in_array($placeholder, $allowed, true)) {
                $problems[] = "{$placeholder} isn't available here. Use ".implode(', ', $allowed).'.';
            }
        }
        if ($key === 'login_code' && ! str_contains($sms, '{1}')) {
            $problems[] = 'The sign-in code text must include the code: {1}.';
        }
        if (($template['link'] ?? null) && ! str_contains($sms, '{link}')) {
            $problems[] = 'Include {link} so people can '.$template['link'].' from the SMS.';
        }

        return $problems;
    }

    public static function required(string $key): bool
    {
        return self::TEMPLATES[$key]['required'] ?? false;
    }

    /** Sample values for the preview and test sends. */
    public static function sample(string $key): Message
    {
        $variables = self::TEMPLATES[$key]['variables'] ?? [];
        $params = array_map(fn (string $v) => match ($v) {
            'code' => '482913',
            'name', 'staff name' => 'Ada',
            'lot', 'seller name' => 'Prime Motors',
            'car', 'example car' => '2018 Toyota Camry',
            'what' => 'Viewing of the 2018 Toyota Camry',
            'when' => 'Sat 14 Dec, 11:00',
            'amount', 'money received' => '₦1,500,000',
            'balance', 'balances due' => '₦3,000,000',
            'price', 'new price' => '₦12,500,000',
            'amount off' => '₦500,000',
            default => ucfirst($v),
        }, $variables);

        $link = (self::TEMPLATES[$key]['link'] ?? null) !== null ? 'b/example' : null;

        return new Message($key, $params, '', $link, $key === 'login_code');
    }

    /** @param  array{whatsapp_template: string, language: string, sms_text: string|null, enabled: bool}  $values */
    public static function save(MessageTemplate $template, array $values, ?User $by = null): void
    {
        $before = $template->only(['whatsapp_template', 'language', 'sms_text', 'enabled']);
        if (self::required($template->key)) {
            $values['enabled'] = true;
        }

        $template->update([...$values, 'updated_by' => $by?->id]);
        Cache::forget(self::CACHE);

        AuditLog::record('admin.message_template_changed', $template, ['before' => $before, 'after' => $template->only(array_keys($before))], $by);
    }

    /** @return array<string, array{whatsapp_template: string, language: string, sms_text: string|null, enabled: bool}> */
    private static function settings(): array
    {
        try {
            return Cache::rememberForever(self::CACHE, fn () => MessageTemplate::query()->get()
                ->mapWithKeys(fn (MessageTemplate $t) => [$t->key => [
                    'whatsapp_template' => $t->whatsapp_template,
                    'language' => $t->language,
                    'sms_text' => $t->sms_text,
                    'enabled' => $t->enabled,
                ]])->all());
        } catch (Throwable) {
            return []; // no table yet or the database is down: send as the code wrote it
        }
    }
}
