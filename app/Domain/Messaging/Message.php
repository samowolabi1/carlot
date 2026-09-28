<?php

namespace App\Domain\Messaging;

/**
 * One message, ready for WhatsApp (a pre-approved Meta template) or SMS (plain text).
 * Business-initiated WhatsApp messages must use templates (TDD: Notification matrix).
 */
final class Message
{
    /**
     * @param  list<string>  $params  template body variables, in order
     * @param  string|null  $buttonSuffix  dynamic part of the template's URL button
     * @param  bool  $authentication  Meta authentication template (one-time codes)
     */
    public function __construct(
        public readonly string $template,
        public readonly array $params,
        public readonly string $text,
        public readonly ?string $buttonSuffix = null,
        public readonly bool $authentication = false,
    ) {}

    /** Path of an app URL, for template URL buttons whose base is the app URL. */
    public static function suffix(string $url): string
    {
        return ltrim(str_replace(rtrim(config('app.url'), '/'), '', $url), '/');
    }
}
