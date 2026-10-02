<?php

namespace App\Enums;

enum ServiceContractType: string
{
    case Development = 'development';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Development => '신규 개발',
            self::Maintenance => '유지보수',
        };
    }
}
