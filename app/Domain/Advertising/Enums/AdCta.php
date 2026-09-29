<?php

namespace App\Domain\Advertising\Enums;

enum AdCta: string
{
    case SeeCars = 'see_cars';
    case ViewCar = 'view_car';
    case BookVisit = 'book_visit';
    case VisitLot = 'visit_lot';
    case SeeOffers = 'see_offers';

    public function label(): string
    {
        return match ($this) {
            self::SeeCars => 'See our cars',
            self::ViewCar => 'View this car',
            self::BookVisit => 'Book a visit',
            self::VisitLot => 'Visit our lot',
            self::SeeOffers => 'See this week\'s offers',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
