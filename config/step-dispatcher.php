<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Valid Queues
    |--------------------------------------------------------------------------
    |
    | List of valid queue names that steps can be dispatched to.
    | The hostname-based queue is automatically added at runtime.
    |
    */
    'queues' => [
        'valid' => ['default', 'priority'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dispatch Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the dispatcher behavior.
    |
    */
    'dispatch' => [
        // Threshold in milliseconds before a tick is considered slow
        'warning_threshold_ms' => 40000,

        // Callback for slow dispatch warnings (receives duration in ms)
        // Example: fn (int $durationMs) => Log::warning("Slow dispatch: {$durationMs}ms")
        'on_slow_dispatch' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Flag Path
    |--------------------------------------------------------------------------
    |
    | Absolute directory path where the dispatcher stores its active flag file.
    | All applications sharing the same database MUST point to the same path
    | (typically the dispatcher app's storage directory).
    |
    | This value is REQUIRED. The dispatcher will throw a RuntimeException
    | if not configured.
    |
    */
    'flag_path' => env('STEP_DISPATCHER_FLAG_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Dispatch Groups
    |--------------------------------------------------------------------------
    |
    | Groups used for round-robin step assignment. Steps are distributed
    | across these groups for parallel processing.
    |
    */
    'groups' => [
        'available' => ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Group Fan-Out Threshold
    |--------------------------------------------------------------------------
    |
    | Controls when a block's children stop inheriting their parent's group
    | and start fanning out across groups via round-robin. When the number
    | of steps in a block reaches this threshold, subsequent Step::create
    | calls on the same block_uuid ignore inheritance and round-robin.
    |
    | Small orchestrator workflows (position lifecycle, sync, WAP) keep
    | their children below the threshold and stay coherent on one group.
    | Batch dispatches (kline fetches, indicator queries, BTC correlation
    | across hundreds of symbols) exceed the threshold and spread across
    | groups so no single group becomes a magnet for cron-driven work.
    |
    | Set to 0 to disable fan-out entirely (pure inheritance semantics).
    |
    */
    'fanout_threshold' => env('STEP_DISPATCHER_FANOUT_THRESHOLD', 50),
];
