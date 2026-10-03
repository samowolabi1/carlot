<?php

namespace App\Domain\Advertising\Enums;

enum AdPlacement: string
{
    /** The rotating banner at the top of the home page, for every buyer who opens CarYard. */
    case HomeBanner = 'home_banner';
    /** A banner among search results and on city/make landing pages; can target a make, body type or city. */
    case SearchBanner = 'search_banner';

    public function label(): string
    {
        return match ($this) {
            self::HomeBanner => 'Homepage banner',
            self::SearchBanner => 'Search banner',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::HomeBanner => 'A large banner at the top of the CarYard home page, shown to every buyer. Up to '.self::HomeBanner->slots().' sellers rotate at a time.',
            self::SearchBanner => 'A banner among search results and on city and make pages. Aim it at buyers looking for a make, body type or city.',
        };
    }

    /** How many can run at the same time. */
    public function slots(): int
    {
        return (int) config("lotlink.adverts.{$this->value}.slots", 5);
    }

    /**
     * Banner image size in pixels.
     *
     * @return array{0: int, 1: int} width, height
     */
    public function size(): array
    {
        return match ($this) {
            self::HomeBanner => [1600, 600],
            self::SearchBanner => [1200, 300],
        };
    }

    public function targetable(): bool
    {
        return $this === self::SearchBanner;
    }
}
