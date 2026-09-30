<?php

namespace App\Domain\Legal;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * The platform's legal pages (Terms of Use, Privacy Policy, Lender Terms, Security and safety), written in
 * resources/legal/*.md with {placeholders} for the company's details from config('lotlink.legal'). Versions live in
 * config too: bump one when a document changes materially and people are asked to accept it again.
 */
final class LegalDocuments
{
    public const TITLES = [
        'terms' => 'Terms of Use',
        'privacy' => 'Privacy Policy',
        'lender-terms' => 'Lender Terms',
        'security' => 'Security and safety',
    ];

    /** Every signed-in person accepts these; lenders' admins also accept the Lender Terms. */
    public const FOR_EVERYONE = ['terms', 'privacy'];

    public static function exists(string $doc): bool
    {
        return isset(self::TITLES[$doc]);
    }

    public static function version(string $doc): string
    {
        return (string) config("lotlink.legal.versions.{$doc}");
    }

    /** One string for "Terms + Privacy as they are now", stored on the user when they accept both. */
    public static function userVersion(): string
    {
        return max(array_map(fn (string $d) => self::version($d), self::FOR_EVERYONE));
    }

    /**
     * The document as safe HTML (raw HTML in the source is stripped) with ids on its sections, and its contents list.
     *
     * @return array{title: string, version: string, effective: string, html: string, sections: list<array{id: string, title: string}>}
     */
    public static function render(string $doc): array
    {
        $version = self::version($doc);

        return Cache::remember("legal:{$doc}:{$version}:".md5(serialize(config('lotlink.legal'))), now()->addDay(), function () use ($doc, $version): array {
            $source = strtr((string) file_get_contents(resource_path("legal/{$doc}.md")), [
                '{company}' => (string) config('lotlink.legal.company'),
                '{rc}' => (string) config('lotlink.legal.rc_number'),
                '{address}' => (string) config('lotlink.legal.address'),
                '{email}' => (string) config('lotlink.legal.email'),
                '{privacy_email}' => (string) config('lotlink.legal.privacy_email'),
                '{security_email}' => (string) config('lotlink.legal.security_email'),
            ]);
            $html = (string) (new GithubFlavoredMarkdownConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]))->convert($source);

            $sections = [];
            $html = (string) preg_replace_callback('#<h2>(.*?)</h2>#', function (array $m) use (&$sections): string {
                $id = Str::slug(strip_tags($m[1]));
                $sections[] = ['id' => $id, 'title' => html_entity_decode(strip_tags($m[1]))];

                return "<h2 id=\"{$id}\">{$m[1]}</h2>";
            }, $html);

            return [
                'title' => self::TITLES[$doc],
                'version' => $version,
                'effective' => Carbon::parse($version)->format('j F Y'),
                'html' => $html,
                'sections' => $sections,
            ];
        });
    }
}
