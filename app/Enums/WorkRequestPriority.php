<?php

namespace App\Enums;

enum WorkRequestPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => '낮음',
            self::Normal => '보통',
            self::High => '높음',
        };
    }
}
