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
        'valid' => ['default', 'priority', 'positions', 'orders', 'cronjobs', 'indicators', 'user-data-stream'],
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

        // Per-tick load shedding: cap how many Pending rows a single tick
        // hydrates per group. Without this cap, a group that accumulates
        // thousands of Pending rows (wedge state, traffic spike) loads them
        // all into memory every second, blows the tick budget, and starves
        // sibling groups. Drains in waves; consistent. Set to 0 to disable
        // (legacy unbounded behaviour).
        'max_per_tick' => env('STEP_DISPATCHER_MAX_PER_TICK', 100),
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
    | Defaults to the storage directory below. The dispatcher throws a
    | RuntimeException if this value is empty.
    |
    */
    'flag_path' => storage_path('step-dispatcher'),

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
    | Diagnostic Logging
    |--------------------------------------------------------------------------
    |
    | Per-step file logging for ad-hoc debugging. When enabled each step gets
    | a folder at {path}/steps/{step_id}/ with channel files (states.log,
    | throttled.log, retries.log, exceptions.log). Tick-level dispatcher
    | events land at {path}/dispatcher.log.
    |
    | Designed as a flip-on-debug-flip-off tool: writes are bypassed entirely
    | when disabled. A step folder is removed when the step model is deleted.
    | steps:archive and steps:purge delete rows in bulk and do not remove
    | folders, so the host manages the retention of these files.
    |
    */
    'logging' => [
        'enabled' => env('STEP_DISPATCHER_LOGGING_ENABLED', false),
        'path' => env('STEP_DISPATCHER_LOGGING_PATH', storage_path('logs')),
    ],
];
