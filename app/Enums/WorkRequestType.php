<?php

namespace App\Enums;

enum WorkRequestType: string
{
    case Feature = 'feature';
    case BugFix = 'bug_fix';
    case Maintenance = 'maintenance';
    case Consultation = 'consultation';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Feature => '기능 개발',
            self::BugFix => '버그 수정',
            self::Maintenance => '유지보수',
            self::Consultation => '상담',
            self::Other => '기타',
        };
    }
}
