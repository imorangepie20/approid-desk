<?php

namespace App\Enums;

enum TimeLedgerType: string
{
    case Provided = 'provided';
    case Reserve = 'reserve';
    case Release = 'release';
    case Usage = 'usage';
    case CancelUsage = 'cancel_usage';
    case AdjustIncrease = 'adjust_increase';
    case AdjustDecrease = 'adjust_decrease';

    public function label(): string
    {
        return match ($this) {
            self::Provided => '제공',
            self::Reserve => '예약',
            self::Release => '예약 해제',
            self::Usage => '실사용',
            self::CancelUsage => '사용 취소',
            self::AdjustIncrease => '조정 증가',
            self::AdjustDecrease => '조정 감소',
        };
    }

    public function availableSign(): int
    {
        return match ($this) {
            self::Provided, self::Release, self::CancelUsage, self::AdjustIncrease => 1,
            self::Reserve, self::Usage, self::AdjustDecrease => -1,
        };
    }
}
