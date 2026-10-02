<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Active = 'active';
    case OnHold = 'on_hold';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => '활성',
            self::OnHold => '보류',
            self::Archived => '보관',
        };
    }
}
