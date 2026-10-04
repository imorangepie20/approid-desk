<?php

namespace App\Console\Commands;

use App\Services\SchedulerHealth;
use Illuminate\Console\Command;

class CheckSchedulerHealth extends Command
{
    protected $signature = 'desk:check-scheduler
        {--max-age= : Maximum allowed heartbeat age in seconds}';

    protected $description = 'Fail when the scheduler heartbeat is missing or stale';

    public function handle(SchedulerHealth $health): int
    {
        $maxAge = $this->maxAge();

        if ($maxAge === null) {
            $this->error('The scheduler heartbeat maximum age must be a positive integer.');

            return self::INVALID;
        }

        $heartbeat = $health->lastHeartbeat();

        if ($heartbeat === null) {
            $this->error('No valid scheduler heartbeat has been recorded.');

            return self::FAILURE;
        }

        $age = now()->getTimestamp() - $heartbeat->getTimestamp();

        if ($age < 0) {
            $this->error('The scheduler heartbeat is ahead of the application clock.');

            return self::FAILURE;
        }

        if ($age > $maxAge) {
            $this->error("Scheduler heartbeat is stale ({$age} seconds old; maximum {$maxAge}).");

            return self::FAILURE;
        }

        $this->info("Scheduler heartbeat is healthy ({$age} seconds old; maximum {$maxAge}).");

        return self::SUCCESS;
    }

    private function maxAge(): ?int
    {
        $value = $this->option('max-age') ?? config('operations.scheduler.heartbeat_max_age_seconds');

        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $validated === false ? null : $validated;
    }
}
