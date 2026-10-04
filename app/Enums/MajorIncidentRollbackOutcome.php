<?php

namespace App\Enums;

enum MajorIncidentRollbackOutcome: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Aborted = 'aborted';

    public function label(): string
    {
        return match ($this) {
            self::Succeeded => '성공',
            self::Failed => '실패',
            self::Aborted => '중단',
        };
    }
}
