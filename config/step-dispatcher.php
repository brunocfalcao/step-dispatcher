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
    | Diagnostic Logging
    |--------------------------------------------------------------------------
    |
    | Per-step file logging for ad-hoc debugging. When enabled each step gets
    | a folder at {path}/steps/{step_id}/ with channel files (states.log,
    | throttled.log, retries.log, exceptions.log). Tick-level dispatcher
    | events land at {path}/dispatcher.log.
    |
    | Designed as a flip-on-debug-flip-off tool: writes are bypassed entirely
    | when disabled, and step folders are removed when the step row is
    | deleted (archive or purge), so the system doesn't accumulate log dirs.
    |
    */
    'logging' => [
        'enabled' => env('STEP_DISPATCHER_LOGGING_ENABLED', false),
        'path' => env('STEP_DISPATCHER_LOGGING_PATH', storage_path('logs')),
    ],
];
