<?php

namespace App\Enums;

enum NotificationPriority: string
{
    case Normal = 'normal';
    case Important = 'important';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Normal => '일반',
            self::Important => '중요',
            self::Urgent => '긴급',
        };
    }
}
