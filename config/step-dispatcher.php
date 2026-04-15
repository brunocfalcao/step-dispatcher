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
];
