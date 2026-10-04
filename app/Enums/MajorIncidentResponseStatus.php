<?php

namespace App\Enums;

enum MajorIncidentResponseStatus: string
{
    case Pending = 'pending';
    case Overdue = 'overdue';
    case Met = 'met';
    case Late = 'late';
    case Unrecorded = 'unrecorded';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '목표 응답 대기',
            self::Overdue => '내부 목표 경과',
            self::Met => '내부 목표 이내 응답',
            self::Late => '내부 목표 이후 응답',
            self::Unrecorded => '응답 미기록',
        };
    }
}
