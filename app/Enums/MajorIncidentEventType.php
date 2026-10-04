<?php

namespace App\Enums;

enum MajorIncidentEventType: string
{
    case FirstResponse = 'first_response';
    case ResponseUpdate = 'response_update';
    case CustomerConsultation = 'customer_consultation';
    case RecoveryConfirmation = 'recovery_confirmation';

    public function label(): string
    {
        return match ($this) {
            self::FirstResponse => '최초 응답',
            self::ResponseUpdate => '대응 진행',
            self::CustomerConsultation => '고객 협의',
            self::RecoveryConfirmation => '복구 확인',
        };
    }
}
