<?php

namespace App\Domain\LotManager\Enums;

use App\Domain\Support\HasOptions;

enum DocumentType: string
{
    use HasOptions;

    case CustomsPapers = 'customs_papers';
    case ProofOfOwnership = 'proof_of_ownership';
    case PlateNumber = 'plate_number';
    case Registration = 'registration';
    case SpareKey = 'spare_key';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CustomsPapers => 'Customs papers',
            self::ProofOfOwnership => 'Proof of ownership',
            self::PlateNumber => 'Plate number',
            self::Registration => 'Vehicle registration',
            self::SpareKey => 'Spare key',
            self::Other => 'Other',
        };
    }

    /**
     * The checklist every new order starts with; the first three must be in before "papers ready".
     *
     * @return array<string, bool> type => mandatory
     */
    public static function defaults(): array
    {
        return [
            self::CustomsPapers->value => true,
            self::ProofOfOwnership->value => true,
            self::PlateNumber->value => true,
            self::Registration->value => false,
            self::SpareKey->value => false,
        ];
    }
}
