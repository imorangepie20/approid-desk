<?php

namespace App\Console\Commands;

use App\Services\SchedulerHealth;
use Illuminate\Console\Command;

class RecordSchedulerHeartbeat extends Command
{
    protected $signature = 'desk:scheduler-heartbeat';

    protected $description = 'Record the current scheduler heartbeat';

    public function handle(SchedulerHealth $health): int
    {
        $recordedAt = $health->record();

        $this->info('Scheduler heartbeat recorded at '.$recordedAt->toIso8601String().'.');

        return self::SUCCESS;
    }
}
