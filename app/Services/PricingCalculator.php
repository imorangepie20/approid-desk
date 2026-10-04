<?php

namespace App\Services;

use InvalidArgumentException;

class PricingCalculator
{
    public const MAX_HOURLY_RATE = 100_000_000;

    public const MAX_MINUTES = 10_000_000;

    public const MAX_SURCHARGE_BPS = 100_000;

    public function calculate(int $hourlyRate, int $minutes, int $urgentSurchargeBps, bool $urgent): int
    {
        if ($hourlyRate < 1 || $hourlyRate > self::MAX_HOURLY_RATE
            || $minutes < 1 || $minutes > self::MAX_MINUTES
            || $urgentSurchargeBps < 0 || $urgentSurchargeBps > self::MAX_SURCHARGE_BPS) {
            throw new InvalidArgumentException('가격 계산 범위를 벗어났습니다.');
        }

        $base = $hourlyRate * $minutes;
        $factor = 10_000 + ($urgent ? $urgentSurchargeBps : 0);

        // Divide first where possible so the supported maximum stays within 64-bit integers.
        return intdiv($base, 600_000) * $factor
            + intdiv(($base % 600_000) * $factor + 599_999, 600_000);
    }
}
