<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use StepDispatcher\Models\StepsDispatcher;

/**
 * Atomicity contract: when startDispatch() recovers a stale lock
 * (can_dispatch=false + updated_at older than the 20-second failsafe), the
 * failsafe reset and the CAS acquire MUST happen inside a single UPDATE
 * statement on steps_dispatcher. Splitting them into two opens a race
 * window where two concurrent processes can both slip through the failsafe
 * reset, both see can_dispatch=true, and both succeed the CAS — causing
 * duplicate dispatches to Redis.
 *
 * The assertion counts UPDATE statements on steps_dispatcher during a single
 * startDispatch() call on a stale lock:
 *   - Broken (two separate writes): 3 UPDATEs — failsafe reset, CAS acquire, tick_id stamp.
 *   - Fixed (merged failsafe + CAS): 2 UPDATEs — combined acquire, tick_id stamp.
 */
it('executes the stale-lock recovery as a single atomic UPDATE', function () {
    // Arrange: stale lock — held by a dead process > 20s ago. Raw insert
    // sidesteps the model's fillable guard since this is pure state setup.
    DB::table('steps_dispatcher')->insert([
        'group' => null,
        'can_dispatch' => false,
        'updated_at' => now()->subSeconds(30),
        'created_at' => now()->subSeconds(30),
        'last_selected_at' => now()->subMinute(),
    ]);

    $updates = [];

    DB::listen(function ($query) use (&$updates) {
        $normalized = mb_ltrim(mb_strtolower($query->sql));

        if (str_starts_with($normalized, 'update') && str_contains($query->sql, 'steps_dispatcher')) {
            $updates[] = $query->sql;
        }
    });

    // Act
    $acquired = StepsDispatcher::startDispatch(group: null);

    // Assert
    expect($acquired)->toBeTrue('failsafe recovery should acquire the lock');

    // 2 UPDATEs expected after the fix: one combined failsafe+CAS, one tick_id stamp.
    // 3 UPDATEs means the failsafe reset is outside the CAS transaction, opening
    // the race we are guarding against.
    expect($updates)->toHaveCount(
        2,
        'startDispatch must merge the stale-lock failsafe reset and the CAS acquire into a single UPDATE statement'
    );
});
