<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [
    'domain' => env('HORIZON_DOMAIN'),
    'path' => env('HORIZON_PATH', 'horizon'),
    'use' => 'default',
    'prefix' => env('HORIZON_PREFIX', Str::slug(env('APP_NAME', 'silaris'), '_').'_horizon:'),
    'middleware' => ['web'],

    'waits' => ['redis:default' => 60],
    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],
    'silenced' => [],
    'metrics' => [
        'trim_snapshots' => ['job' => 24, 'queue' => 24],
    ],
    'fast_termination' => false,
    'memory_limit' => 128,

    // Files consommées : default (jobs génériques) + odoo (sync ERP, backoff long).
    'defaults' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['default', 'odoo'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 3,
            'maxTime' => 3600,
            'maxJobs' => 1000,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 120,
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => ['supervisor-1' => ['maxProcesses' => 3]],
        'staging' => ['supervisor-1' => ['maxProcesses' => 2]],
        'local' => ['supervisor-1' => ['maxProcesses' => 2]],
    ],
];
