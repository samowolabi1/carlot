<?php

namespace App\Domain\Helpdesk\Enums;

enum TicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low: whenever you can',
            self::Normal => 'Normal',
            self::High => 'High: affecting sales',
            self::Urgent => 'Urgent: lot can\'t work',
        };
    }

    public function short(): string
    {
        return ucfirst($this->value);
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $p) => ['value' => $p->value, 'label' => $p->label()], self::cases());
    }
}
