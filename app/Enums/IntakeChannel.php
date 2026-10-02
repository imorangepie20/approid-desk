<?php

namespace App\Enums;

enum IntakeChannel: string
{
    case Web = 'web';
    case Phone = 'phone';
    case Email = 'email';
    case Messenger = 'messenger';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Web => '웹사이트',
            self::Phone => '전화',
            self::Email => '이메일',
            self::Messenger => '메신저',
            self::Other => '기타',
        };
    }
}
