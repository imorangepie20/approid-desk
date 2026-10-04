<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

class SchedulerHealth
{
    public const CACHE_KEY = 'operations:scheduler:last-heartbeat-at';

    public function record(): CarbonInterface
    {
        $recordedAt = Date::now();

        Cache::forever(self::CACHE_KEY, $recordedAt->toIso8601String());

        return $recordedAt;
    }

    public function lastHeartbeat(): ?CarbonImmutable
    {
        $value = Cache::get(self::CACHE_KEY);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
