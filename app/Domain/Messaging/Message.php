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
     * @param  string|null  $language  template language; null uses the gateway's default
     */
    public function __construct(
        public readonly string $template,
        public readonly array $params,
        public readonly string $text,
        public readonly ?string $buttonSuffix = null,
        public readonly bool $authentication = false,
        public readonly ?string $language = null,
    ) {}

    /** A copy with the admin's settings applied (see `MessageCatalogue::apply()`). */
    public function with(string $template, ?string $language, string $text): self
    {
        return new self($template, $this->params, $text, $this->buttonSuffix, $this->authentication, $language);
    }

    /** The full URL behind the template's button, for SMS wording. */
    public function link(): ?string
    {
        return $this->buttonSuffix !== null ? rtrim((string) config('app.url'), '/').'/'.$this->buttonSuffix : null;
    }

    /** Path of an app URL, for template URL buttons whose base is the app URL. */
    public static function suffix(string $url): string
    {
        return ltrim(str_replace(rtrim(config('app.url'), '/'), '', $url), '/');
    }
}
