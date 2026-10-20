<?php

declare(strict_types=1);

namespace StepDispatcher\Support;

use Illuminate\Support\Carbon;
use Illuminate\Database\Schema\Builder;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsCooldown;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;

/**
 * Cooldown mode: stop starting new work while letting started work end.
 *
 * Steps keep being created during a cooldown; they stay Pending. The unit
 * of work is the top-level block (the steps whose block is no other
 * step's child block). A top-level block that has not started does not
 * start. A top-level block that has started is atomic: every remaining
 * step in it, and every step anywhere below it, still runs to the end.
 * The dispatcher's bookkeeping (cascades, parent completion) never pauses.
 *
 * A cooldown is global (`scope = null`) or limited to one scope, a scope
 * being one table set named by its prefix (`''` is the default set,
 * `'trading'` the `trading_steps` set). A global warmup ends every
 * cooldown; a scope warmup ends only that scope's own cooldown, so a
 * global cooldown still holds the scope.
 *
 * Callers use the StepDispatcher facade: cooldown(), warmup(),
 * isCooldownActive(), isInCoolDown() and isCooledDown().
 */
final class Cooldown
{
    /** Set once the cooldowns table is known to exist. */
    private static bool $tableReady = false;

    /** Until when a missing cooldowns table is taken as missing without asking again. */
    private static int $missingUntil = 0;

    /**
     * Per scope ('*' for a global-only look): whether the last look in this
     * process found a cooldown in force.
     *
     * @var array<string, bool>
     */
    private static array $lastSeenActive = [];

    /** How long a missing table is remembered, so hosts that never migrate pay one check a minute. */
    private const MISSING_TABLE_RECHECK_SECONDS = 60;

    /** Ancestor walk ceiling; real trees are a handful of levels deep. */
    private const MAX_DEPTH = 64;

    /** Matches StepsDispatcher::startDispatch(): an older held lock is a dead tick. */
    private const TICK_FAILSAFE_SECONDS = 20;

    public static function start(?string $scope = null): void
    {
        $now = now();

        // Idempotent and race-safe: a second cooldown keeps the first one's time.
        StepsCooldown::query()->insertOrIgnore([
            'scope' => self::key($scope),
            'cooled_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public static function end(?string $scope = null): void
    {
        $query = StepsCooldown::query();

        if ($scope !== null) {
            $query->where('scope', self::key($scope));
        }

        $query->delete();
    }

    /**
     * Global (`null`): is the global cooldown on? A scope: is that scope
     * held, by its own cooldown or by the global one?
     */
    public static function isActive(?string $scope = null): bool
    {
        if (! self::tableReady()) {
            return false;
        }

        $keys = $scope === null
            ? [StepsCooldown::GLOBAL_SCOPE]
            : [StepsCooldown::GLOBAL_SCOPE, self::key($scope)];

        return self::$lastSeenActive[$scope === null ? StepsCooldown::GLOBAL_SCOPE : self::key($scope)]
            = StepsCooldown::query()->whereIn('scope', $keys)->exists();
    }

    /**
     * When the scope's cooldown started (the global one counts too), or
     * null when the scope is not held. `null` asks for the global one.
     */
    public static function since(?string $scope = null): ?Carbon
    {
        if (! self::tableReady()) {
            return null;
        }

        $keys = $scope === null
            ? [StepsCooldown::GLOBAL_SCOPE]
            : [StepsCooldown::GLOBAL_SCOPE, self::key($scope)];

        $since = StepsCooldown::query()->whereIn('scope', $keys)->min('cooled_at');

        return $since === null ? null : Carbon::parse($since);
    }

    /**
     * True while the scope is held and started work is still finishing.
     * `null` asks whether any scope is in that position.
     */
    public static function isCoolingDown(?string $scope = null): bool
    {
        foreach (self::scopesToCheck($scope) as $prefix) {
            if (self::isActive($prefix) && Steps::usingPrefix($prefix, self::hasStartedWork(...))) {
                return true;
            }
        }

        return false;
    }

    /**
     * True once the scope is held and nothing started is left to finish.
     * `null` asks whether every scope is in that position.
     */
    public static function isCooledDown(?string $scope = null): bool
    {
        $scopes = self::scopesToCheck($scope);

        if ($scopes === []) {
            return false;
        }

        foreach ($scopes as $prefix) {
            if (! self::isActive($prefix) || Steps::usingPrefix($prefix, self::hasStartedWork(...))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The table sets installed in this database, as normalised prefixes
     * (`''` first for the default set). A set counts when both its steps
     * table and its dispatcher table exist.
     *
     * @return list<string>
     */
    public static function scopes(): array
    {
        $schema = self::schema();
        // Only this connection's own schemas: MySQL would otherwise list
        // every database on the server, Postgres every schema.
        $current = method_exists($schema, 'getCurrentSchemaListing') ? $schema->getCurrentSchemaListing() : null;
        $names = collect($schema->getTables($current))->pluck('name')->all();
        $prefixes = [];

        foreach ($names as $name) {
            if (str_ends_with($name, 'steps_dispatcher')) {
                $prefix = substr($name, 0, -strlen('steps_dispatcher'));

                if (in_array($prefix.'steps', $names, true)) {
                    $prefixes[] = $prefix;
                }
            }
        }

        sort($prefixes);

        return array_values(array_unique($prefixes));
    }

    /**
     * For one tick of the active scope: null when the scope is not held
     * (nothing to filter), otherwise the blocks the tick may still
     * dispatch from — every block with Pending steps of this group whose
     * top-level block has started.
     *
     * @return list<string>|null
     */
    public static function dispatchableBlocks(?string $group): ?array
    {
        try {
            if (! self::isActive(Steps::currentPrefix())) {
                return null;
            }

            $pendingBlocks = Step::pending()
                ->forGroup($group)
                ->distinct()
                ->pluck('block_uuid')
                ->all();

            return self::blocksOfStartedTrees($pendingBlocks);
        } catch (\Throwable $e) {
            // Unknown cooldown state. A host that never cools down must never
            // be held, so this tick holds new work only when the last look
            // found a cooldown in force; the bookkeeping runs either way. The
            // table is looked at again next tick, in case it was dropped or
            // recreated under a long-lived process.
            report($e);
            $held = (self::$lastSeenActive[self::key(Steps::currentPrefix())] ?? false)
                || (self::$lastSeenActive[StepsCooldown::GLOBAL_SCOPE] ?? false);
            self::flushState();

            return $held ? [] : null;
        }
    }

    /**
     * Does the active scope still hold started work: a step handed to a
     * worker or running, or a Pending step whose top-level block started?
     */
    public static function hasStartedWork(): bool
    {
        if (Step::query()->whereIn('state', [Dispatched::class, Running::class])->exists()) {
            return true;
        }

        if (self::hasTickFromBeforeCooldown()) {
            return true;
        }

        $pendingBlocks = Step::pending()->distinct()->pluck('block_uuid')->all();

        return self::blocksOfStartedTrees($pendingBlocks) !== [];
    }

    /**
     * Is a dispatcher tick of the active scope still running that took its
     * cooldown snapshot before the cooldown began? Such a tick can still
     * hand out a step of an unstarted block, so the scope is not cooled
     * until it ends. A lock older than the dispatcher's 20-second failsafe
     * belongs to a dead tick and does not count.
     */
    public static function hasTickFromBeforeCooldown(): bool
    {
        $since = self::since(Steps::currentPrefix());

        if ($since === null) {
            return false;
        }

        return StepsDispatcher::query()
            ->where('can_dispatch', false)
            ->where('updated_at', '>', now()->subSeconds(self::TICK_FAILSAFE_SECONDS))
            ->where('updated_at', '<=', $since)
            ->exists();
    }

    /**
     * The given blocks whose top-level block has started. Each block is
     * walked up through the step whose child block it is, one query per
     * tree level, until it reaches a block that is nobody's child.
     *
     * @param  array<int, string>  $blockUuids
     * @return list<string>
     */
    public static function blocksOfStartedTrees(array $blockUuids): array
    {
        $blockUuids = array_values(array_unique(array_filter($blockUuids)));

        if ($blockUuids === []) {
            return [];
        }

        $rootOf = array_combine($blockUuids, $blockUuids);
        $frontier = $blockUuids;

        for ($depth = 0; $frontier !== [] && $depth < self::MAX_DEPTH; $depth++) {
            $parentOf = [];

            foreach (array_chunk($frontier, 500) as $chunk) {
                Step::query()
                    ->whereIn('child_block_uuid', $chunk)
                    ->toBase()
                    ->get(['block_uuid', 'child_block_uuid'])
                    ->each(static function (object $row) use (&$parentOf): void {
                        $parentOf[$row->child_block_uuid] ??= $row->block_uuid;
                    });
            }

            if ($parentOf === []) {
                break;
            }

            foreach ($rootOf as $block => $root) {
                if (isset($parentOf[$root])) {
                    $rootOf[$block] = $parentOf[$root];
                }
            }

            $frontier = array_values(array_unique(array_values($parentOf)));
        }

        $startedRoots = array_flip(self::startedBlocks(array_values(array_unique($rootOf))));

        return array_values(array_keys(array_filter(
            $rootOf,
            static fn (string $root): bool => isset($startedRoots[$root]),
        )));
    }

    /**
     * Test hook: forget that the cooldowns table was seen.
     *
     * @internal
     */
    public static function flushState(): void
    {
        self::$tableReady = false;
        self::$missingUntil = 0;
        self::$lastSeenActive = [];
    }

    /**
     * The given blocks holding at least one step that has started: any
     * step past Pending (a dormant resolve-exception does not count), or
     * a Pending step that was already handed out once and came back
     * (throttled or retried).
     *
     * @param  array<int, string>  $blockUuids
     * @return list<string>
     */
    private static function startedBlocks(array $blockUuids): array
    {
        $started = [];

        foreach (array_chunk($blockUuids, 500) as $chunk) {
            $rows = Step::query()
                ->whereIn('block_uuid', $chunk)
                ->where(static function ($query): void {
                    $query->whereNotIn('state', [Pending::class, NotRunnable::class])
                        ->orWhereNotNull('tick_id')
                        ->orWhere('retries', '>', 0)
                        ->orWhere('was_throttled', true);
                })
                ->distinct()
                ->pluck('block_uuid')
                ->all();

            array_push($started, ...$rows);
        }

        return $started;
    }

    /** @return list<string> */
    private static function scopesToCheck(?string $scope): array
    {
        return $scope === null ? self::scopes() : [Steps::normalise($scope)];
    }

    private static function key(?string $scope): string
    {
        return $scope === null ? StepsCooldown::GLOBAL_SCOPE : Steps::normalise($scope);
    }

    private static function tableReady(): bool
    {
        if (self::$tableReady) {
            return true;
        }

        // A host that updated the package but has not migrated yet cannot
        // have set a cooldown, so dispatch carries on as before.
        if (now()->getTimestamp() < self::$missingUntil) {
            return false;
        }

        self::$tableReady = self::schema()->hasTable((new StepsCooldown)->getTable());

        if (! self::$tableReady) {
            self::$missingUntil = now()->getTimestamp() + self::MISSING_TABLE_RECHECK_SECONDS;
        }

        return self::$tableReady;
    }

    private static function schema(): Builder
    {
        return (new Step)->getConnection()->getSchemaBuilder();
    }
}
