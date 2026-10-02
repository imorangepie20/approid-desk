<?php

namespace App\Enums;

enum ServiceContractStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => '작성 중',
            self::Active => '유효',
            self::Expired => '만료',
            self::Cancelled => '취소',
        };
    }
}
