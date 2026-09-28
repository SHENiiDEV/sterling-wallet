<?php

namespace App\Enums;

enum DocumentType: string
{
    case Contract = 'contract';
    case Agreement = 'agreement';
    case Kyb = 'kyb';
    case Invoice = 'invoice';
    case Offer = 'offer';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Contract => 'Contract',
            self::Agreement => 'Agreement',
            self::Kyb => 'KYB',
            self::Invoice => 'Invoice',
            self::Offer => 'Commercial offer',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type) => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
