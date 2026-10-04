<?php

return [
    'scheduler' => [
        'heartbeat_max_age_seconds' => (int) env('SCHEDULER_HEARTBEAT_MAX_AGE_SECONDS', 180),
    ],
];
