<?php

namespace App\Enums;

enum PricingDecision: string
{
    case Feasible = 'feasible';
    case Rejected = 'rejected';
    case Renegotiation = 'renegotiation';

    public function label(): string
    {
        return match ($this) {
            self::Feasible => '견적 가능',
            self::Rejected => '거절',
            self::Renegotiation => '재협의',
        };
    }
}
