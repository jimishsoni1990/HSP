<?php

return [
    'default' => 'database',
    'connections' => [
        'database' => [
            'driver' => 'database',
            'table' => 'system.queue_jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'max_attempts' => 10,
        ],
    ],
];
