<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use StepDispatcher\Models\StepsDispatcher;

/**
 * Lock-order contract for `steps:recover-stale --release-locks`.
 *
 * endDispatch() locks its steps_dispatcher row through the primary key and
 * then updates the can_dispatch index. A bulk release filtered on
 * can_dispatch locks that index first and then the primary key, so the two
 * deadlock on MySQL when a slow tick ends while the sweep runs (production,
 * 2026-11-02). The sweep must read its candidates without locking and then
 * release each row by primary key, re-checking that the row is still stale.
 */
function insertLockedLane(string $group, int $tickId, int $secondsAgo): int
{
    return (int) DB::table(StepsDispatcher::tableName())->insertGetId([
        'group' => $group,
        'can_dispatch' => false,
        'current_tick_id' => $tickId,
        'updated_at' => now()->subSeconds($secondsAgo),
        'created_at' => now()->subSeconds($secondsAgo),
    ]);
}

function dispatcherRow(int $id): object
{
    return DB::table(StepsDispatcher::tableName())->where('id', $id)->first();
}

it('releases every stale dispatcher lock by its primary key', function (): void {
    $token = bin2hex(random_bytes(6));
    $firstId = insertLockedLane("first-{$token}", 301, 90);
    $secondId = insertLockedLane("second-{$token}", 302, 60);
    $freshId = insertLockedLane("fresh-{$token}", 303, 5);

    $updates = [];

    DB::listen(function ($query) use (&$updates): void {
        if (str_starts_with(mb_ltrim(mb_strtolower($query->sql)), 'update')
            && str_contains($query->sql, StepsDispatcher::tableName())) {
            $updates[] = $query->sql;
        }
    });

    expect((bool) dispatcherRow($firstId)->can_dispatch)->toBeFalse()
        ->and((bool) dispatcherRow($secondId)->can_dispatch)->toBeFalse();

    expect(Artisan::call('steps:recover-stale', [
        '--release-locks' => true,
        '--lock-threshold' => 30,
    ]))->toBe(0);

    expect($updates)->toHaveCount(2);

    foreach ($updates as $sql) {
        expect($sql)->toMatch('/\bwhere\s+["`]?id["`]?\s*=\s*\?/i');
    }

    expect((bool) dispatcherRow($firstId)->can_dispatch)->toBeTrue()
        ->and(dispatcherRow($firstId)->current_tick_id)->toBeNull()
        ->and((bool) dispatcherRow($secondId)->can_dispatch)->toBeTrue()
        ->and(dispatcherRow($secondId)->current_tick_id)->toBeNull()
        ->and((bool) dispatcherRow($freshId)->can_dispatch)->toBeFalse()
        ->and((int) dispatcherRow($freshId)->current_tick_id)->toBe(303);
});

it('leaves a lane alone when a new tick takes its lock after the stale read', function (): void {
    $token = bin2hex(random_bytes(6));
    $laneId = insertLockedLane("relocked-{$token}", 401, 90);
    $relocked = false;

    // The lane's old tick ends and a new tick locks the row between the
    // sweep's candidate read and its release.
    DB::listen(function ($query) use (&$relocked, $laneId): void {
        if ($relocked
            || ! str_starts_with(mb_ltrim(mb_strtolower($query->sql)), 'select')
            || ! str_contains($query->sql, StepsDispatcher::tableName())
            || ! preg_match('/["`]?can_dispatch["`]?\s*=\s*\?/', $query->sql)
            || ! preg_match('/["`]?updated_at["`]?\s*<\s*\?/', $query->sql)) {
            return;
        }

        $relocked = true;

        DB::table(StepsDispatcher::tableName())->where('id', $laneId)->update([
            'can_dispatch' => false,
            'current_tick_id' => 402,
            'updated_at' => now(),
        ]);
    });

    expect((int) dispatcherRow($laneId)->current_tick_id)->toBe(401);

    expect(Artisan::call('steps:recover-stale', [
        '--release-locks' => true,
        '--lock-threshold' => 30,
    ]))->toBe(0);

    expect($relocked)->toBeTrue()
        ->and((bool) dispatcherRow($laneId)->can_dispatch)->toBeFalse()
        ->and((int) dispatcherRow($laneId)->current_tick_id)->toBe(402);
});

it('keeps sweeping when one lock release loses a deadlock', function (): void {
    $token = bin2hex(random_bytes(6));
    $deadlockedId = insertLockedLane("deadlocked-{$token}", 501, 90);
    $releasedId = insertLockedLane("released-{$token}", 502, 60);
    $thrown = false;

    // MySQL picks the sweep as the deadlock victim and rolls its statement
    // back before anything is written.
    DB::connection()->beforeExecuting(function (string $sql, array $bindings) use (&$thrown): void {
        if ($thrown
            || ! str_starts_with(mb_ltrim(mb_strtolower($sql)), 'update')
            || ! str_contains($sql, StepsDispatcher::tableName())) {
            return;
        }

        $thrown = true;

        throw new QueryException('testing', $sql, $bindings, new PDOException(
            'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'
        ));
    });

    expect(Artisan::call('steps:recover-stale', [
        '--release-locks' => true,
        '--lock-threshold' => 30,
    ]))->toBe(0);

    expect($thrown)->toBeTrue()
        ->and((bool) dispatcherRow($deadlockedId)->can_dispatch)->toBeFalse()
        ->and((int) dispatcherRow($deadlockedId)->current_tick_id)->toBe(501)
        ->and((bool) dispatcherRow($releasedId)->can_dispatch)->toBeTrue()
        ->and(dispatcherRow($releasedId)->current_tick_id)->toBeNull();
});

it('does not hide database errors that are not lock conflicts', function (): void {
    $token = bin2hex(random_bytes(6));
    insertLockedLane("broken-{$token}", 601, 90);

    DB::connection()->beforeExecuting(function (string $sql, array $bindings): void {
        if (str_starts_with(mb_ltrim(mb_strtolower($sql)), 'update')
            && str_contains($sql, StepsDispatcher::tableName())) {
            throw new QueryException('testing', $sql, $bindings, new PDOException('SQLSTATE[HY000]: General error: 1 disk I/O error'));
        }
    });

    Artisan::call('steps:recover-stale', [
        '--release-locks' => true,
        '--lock-threshold' => 30,
    ]);
})->throws(QueryException::class, 'disk I/O error');
