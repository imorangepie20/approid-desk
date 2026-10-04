<?php

namespace App\Enums;

enum NotificationDeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => '처리 중',
            self::Sent => '발송 완료',
            self::Failed => '최종 실패',
        };
    }
}
