# Changelog

All notable changes to this project will be documented in this file.

## 1.11.3 - 2026-04-22

### Features

- [NEW FEATURE] `Transitions\DispatchedToPending` — new state transition registered in `StepStatus`. Lets a step move back to `Pending` from `Dispatched` with retries incrementing (unless throttled), timers reset, and a diagnostic log entry. Enables re-queueing of orphaned Dispatched zombies that would otherwise stay stuck forever.

### Improvements

- [IMPROVED] `steps:recover-stale --recover-dispatched` — now actually re-queues stuck Dispatched steps instead of only renaming their queue column. After the promotion to `queue=priority + priority=high`, the command transitions each step `Dispatched → Pending` via the new transition, so the next dispatcher tick re-pushes them to Redis with a fresh payload. The CRITICAL branch (steps already on priority) also re-queues now, giving zombies another shot rather than silently dying. Duplicate-execution risk absorbed by `BaseStepJob::prepareJobExecution()` which bails when it sees the step already in Running state.

## 1.11.2 - 2026-04-22

### Improvements

- [IMPROVED] Added `README.md` — user-facing orientation covering install, schedule wiring, quick-start job example, parent/child workflows, lifecycle hooks, throttling pattern, the `StaleStepsDetected` event, commands, groups/scaling, config, diagnostic logging, and states table.

## 1.11.1 - 2026-04-22

### Improvements

- [IMPROVED] `config/step-dispatcher.php` — extended `queues.valid` to include Kraite's domain queue names (`positions`, `orders`, `cronjobs`, `indicators`) alongside the existing `default` + `priority`. Consumer apps that use additional queue names now pass the observer's queue-validation check; previously unknown names were silently rewritten to `default`.

## 1.11.0 - 2026-04-22

### Features

- [NEW FEATURE] `StepDispatcher\Events\StaleStepsDetected` — event fired by `steps:recover-stale` whenever the dispatcher surfaces a stall condition. Payload carries severity (`warning`/`critical`), reason (`stale_running_steps_recovered`, `stale_dispatched_steps_promoted`, `stale_dispatched_steps_still_stuck`, `stale_dispatcher_locks_released`), count, already-promoted count, promoted count, released-locks count, oldest step model, and a free-form context array. Consuming apps listen and decide how to react (pushover, Slack, Sentry breadcrumb). The package itself never notifies anyone.
- [NEW FEATURE] `steps:recover-stale --recover-dispatched` — opt-in flag. Scans Dispatched steps stuck past `--step-threshold` seconds (default 300) and promotes them to `queue=priority, priority=high` so a free worker picks them up next tick. When every stuck step was already promoted on a prior run the flag surfaces a CRITICAL event instead of promoting again.
- [NEW FEATURE] `steps:recover-stale --release-locks` — opt-in flag. Force-releases `steps_dispatcher` rows held by a dead tick for longer than `--lock-threshold` seconds (default 30). Fires a warning event listing how many locks were freed.

### Improvements

- [IMPROVED] `steps:recover-stale` default behaviour (no flags) is identical to 1.10 — Running-state recovery only. All new behaviours are opt-in via flags so every existing caller keeps working without changes.
- [IMPROVED] Running-state recovery now fires `StaleStepsDetected` when it actually recovered at least one step, so operators get the same visibility as the Dispatched/locks paths.

### Consolidation

- Replaces the per-app `kraite:cron-check-stale-data` command (lived in `kraitebot/core`). All generic dispatcher-health responsibilities — promotion to priority queue, wedged-lock release, stall detection — are now owned by this package. App-specific concerns (notification channels, throttle duration, recipient user) stay in the consuming app, wired via the new event.

## 1.10.0 - 2026-04-22

### Features

- [NEW FEATURE] `BaseStepJob::$jobBackoffMs` — opt-in millisecond-precision retry backoff. When set to a positive value, `retryJob()` and `rescheduleWithoutRetry()` schedule the next dispatch with `addMilliseconds($jobBackoffMs)` instead of `addSeconds($jobBackoffSeconds)`. Callers that need sub-second precision (API throttler paths where min-delay deficits are tens of ms) can now schedule retries at the exact remainder instead of rounding up to the next whole second. Default is `0` (feature off) — legacy seconds-based backoff is unchanged for every existing caller.
- [NEW FEATURE] Migration `upgrade_dispatch_after_to_millisecond_precision` — promotes `steps.dispatch_after` and `steps_archive.dispatch_after` from `TIMESTAMP` to `TIMESTAMP(3)` via Laravel's Schema Builder (portable across MySQL and PostgreSQL). Widening the column is zero-data-loss; existing second-precision values remain valid. Required so the new `jobBackoffMs` path can actually store sub-second retry targets instead of truncating at column write.

### Improvements

- [IMPROVED] Extracted `resolveNextDispatchTime()` and `resolveBackoffLabel()` helpers in `HandlesStepLifecycle` so `retryJob()` and `rescheduleWithoutRetry()` share the same ms-vs-seconds decision (and matching log label: `1500ms` vs `10s`) instead of duplicating `now()->addSeconds(...)` inline.
- [IMPROVED] `HandlesStepExceptions::retryJobWithBackoff` — only writes `dispatch_after` directly when the database exception handler has its own exponential backoff. For every other exception, delegation to `retryJob()` is complete, so the `jobBackoffMs` override is honoured on the API throttler path.

## 1.9.0 - 2026-04-21

### Features

- [NEW FEATURE] Per-step file-based diagnostic logging. Gated by `STEP_DISPATCHER_LOGGING_ENABLED` (default false). When enabled, each step gets a folder at `storage/logs/steps/{id}/` with channel files: `states.log` (every state transition + creation marker), `throttled.log` (reschedule-without-retry events), `retries.log` (retry cycles), `exceptions.log` (caught exceptions + handler decisions). Tick-level dispatcher events land at `storage/logs/dispatcher.log`. Folders are removed automatically in `StepObserver::deleted` when the step row is archived or purged, so log dirs don't accumulate. Writes are bypassed entirely when the flag is off — zero disk I/O in production unless debugging is active.
- [NEW FEATURE] `step-dispatcher.logging.path` config (env: `STEP_DISPATCHER_LOGGING_PATH`) — override the base log directory (defaults to `storage_path('logs')`).

### Improvements

- [IMPROVED] Rewrote `HasStepLogging` trait with a channel-based API: `Step::log($id, $channel, $message)` per-step, `Step::logGlobal($channel, $message)` for dispatcher-wide events. Replaces the single-file `log()` signature that was never wired to any call site.
- [IMPROVED] Wired logging calls into every state transition (`PendingToDispatched`, `DispatchedToRunning`, `RunningToCompleted`, all Failed/Skipped/Cancelled/Stopped/Pending paths), `HandlesStepLifecycle::retryJob` and `rescheduleWithoutRetry`, `HandlesStepExceptions::handleException`, and `StepDispatcher::dispatch` tick start/finish.
- [IMPROVED] `StepObserver::created` writes an initial `CREATED` line to `states.log` so every step has a trace from birth, even if it never executes (cancelled before dispatch, skipped, etc.).

## 1.8.5 - 2026-04-21

### Fixes

- [BUG FIX] `StepsDispatcherTicks::steps()` relation now explicitly sets `tick_id` as the foreign key. The default `hasMany(Step::class)` resolved to `steps_dispatcher_ticks_id` (nonexistent column), making the relation throw on access.

### Improvements

- [IMPROVED] `BaseStepJob::finalizeJobExecution` — removed redundant `if ($this->shouldComplete())` wrapper. `shouldComplete()` returns `void` (always falsy), making the guarded call unreachable; `shouldComplete()` already invokes `complete()` internally.
- [IMPROVED] `StepObserver::creating` — removed duplicate queue/priority/state/group normalization blocks. The `saving` hook fires before `creating` on Eloquent inserts and already handles these, so the duplicates in `creating` never fired.
- [IMPROVED] `HandlesStepLifecycle::shouldDoubleCheck` — collapsed redundant branching; removed unreachable final `return false;`.
- [IMPROVED] `RecoverStaleStepsCommand::hasActiveDescendants` — removed unreachable `empty($parent->child_block_uuid)` guard; the method is only called after `isParent()` already guarantees `child_block_uuid` is set.
- [IMPROVED] `PendingToDispatched::canTransition` — removed unreachable defensive guards: `if (! $parent) return false;` (isChild and getParentStep share the same query) and the final not-orphan/not-child/not-parent fallback (impossible combination).
- [IMPROVED] `PendingToDispatched` — removed unused private `isParent()` helper.
- [IMPROVED] `StepDispatcher::computeDispatchableSteps` — removed unreachable final `return false;` fallback; the remaining case after orphan/child elimination is always a parent step.

## 1.8.4 - 2026-04-21

### Improvements

- [IMPROVED] Extend `idx_steps_class_state` with `is_throttled` as a trailing column (renamed to `idx_steps_class_state_throttled`). Admin observability dashboards that group by `(class, state, is_throttled)` now use a covering index (loose index scan, no temp table) instead of a full-table scan on a 300K+ row `steps` table. Wall-clock on that aggregation dropped from ~5s to ~400ms in production. Callers grouping or filtering by `(class, state)` alone continue to use the same index via the left-prefix rule.

## 1.8.3 - 2026-04-21

### Fixes

- [BUG FIX] `RecoverStaleStepsCommand` now skips parent steps with active (non-terminal) descendants. Previously, orchestrator-type steps whose own `compute()` ran fast but whose child block took >360s to settle (slow exchanges, TAAPI throttling, or large position trees) were flipped Running → Pending by recover-stale and re-executed from scratch, duplicating child dispatches and DB side effects (e.g. extra Position rows from `PreparePositionsOpeningJob`). Genuine zombie parents (no children, or all descendants terminal yet parent stuck Running) are still recovered.

## 1.8.2 - 2026-04-20

### Fixes

- [BUG FIX] `RecoverStaleStepsCommand` no longer treats Laravel's `$timeout = 0` convention (meaning "use queue worker timeout") as a literal zero — when the reflected value is 0, falls back to `DEFAULT_TIMEOUT` (300s). Previously, jobs without an explicit `$timeout` were considered stale after 60s (0 + BUFFER), which ping-ponged legitimate long-running jobs between Pending → Running → killed → Pending until retries exhausted.

## 1.8.1 - 2026-04-20

### Dependencies

- [DEPENDENCIES] Allow Illuminate `^13.0` alongside `^11.0` / `^12.0` on `support`, `database`, `console`, and `queue` so the package installs on Laravel 13 hosts.

## 1.8.0 - 2026-04-15

### Fixes

- [BUG FIX] Guard against terminal state execution in `prepareJobExecution()` — when a step is cancelled between dispatch and worker pickup, the worker now bails out silently instead of attempting unregistered state transitions that caused an infinite retry loop (64GB log in one day under Horizon `--tries=0`)

### Features

- [NEW FEATURE] Make flag_path configurable via `STEP_DISPATCHER_FLAG_PATH` env variable — allows multiple apps sharing the same database to use a single flag file location

## 1.7.0 - 2026-04-14

### Features

- [NEW FEATURE] Add `steps:archive` command — moves fully-resolved step trees to `steps_archive` table, keeping `steps` table lean
- [NEW FEATURE] Add `steps_archive` migration — mirrors `steps` schema with minimal indexes for historical lookups

### Improvements

- [IMPROVED] Wrap archive INSERT+DELETE in `DB::transaction()` to prevent data loss on crash

## 1.6.1 - 2026-04-14

### Features

- [NEW FEATURE] Add `PgsqlDatabaseExceptionHandler` with PostgreSQL-specific SQLSTATE codes for retry, permanent, and ignorable error classification

### Fixes

- [BUG FIX] Fix PostgreSQL compatibility in `BaseDatabaseExceptionHandler::make()` — add missing `PgsqlDatabaseExceptionHandler` import
- [BUG FIX] Fix PostgreSQL syntax error in `collectAllNestedChildBlocks` — replace MySQL backtick quoting on reserved word `group` with grammar-aware `DB::getQueryGrammar()->wrap()`
- [BUG FIX] Fix PostgreSQL `NOW(6)` unsupported function in `StepsDispatcher::getNextGroup()` — use `clock_timestamp()` for pgsql driver
- [BUG FIX] Fix PostgreSQL migration syntax in `alter_steps_dispatcher_last_selected_at` — use `ALTER COLUMN ... TYPE` instead of MySQL `MODIFY`

## 1.6.0 - 2026-04-13

### Features

- [NEW FEATURE] Add idle mode — dispatcher skips all DB queries when no active steps exist (Pending/Dispatched/Running), using a file-based flag at `storage/step-dispatcher/active.flag`
- [NEW FEATURE] Add `StepDispatcher::hasActiveSteps()` — sub-millisecond EXISTS query to check for active steps
- [NEW FEATURE] Add `StepDispatcher::activate()` / `deactivate()` / `isActive()` for flag management
- [NEW FEATURE] Add `StepsDispatcher::recordTickWhen()` — register a callable to conditionally persist tick records (e.g., only ticks > 5 seconds)
- [NEW FEATURE] Add `--ticks` flag to `steps:purge` command — purges historical ticks that don't pass the `recordTickWhen` callable

### Improvements

- [IMPROVED] `StepObserver::created()` now activates the dispatcher flag automatically on step creation
- [IMPROVED] Dispatcher deactivates flag in `finally` block when no active steps remain
- [IMPROVED] Simplified `endDispatch()` — collapsed duplicate branches, callable evaluated in all code paths
- [IMPROVED] Cleaned up `StepObserver::saving()` — removed redundant `get_class()` checks alongside `instanceof`

## 1.5.0 - 2026-04-13

### Improvements

- [IMPROVED] Optimize steps table indexes — drop 22 redundant single-column and overlapping composite indexes, add 2 new composites for uncovered query patterns (class+state, state+updated_at). Result: 27 indexes → 11 plus PK.

## 1.4.0 - 2026-04-11

### Features

- [NEW FEATURE] Add `steps:purge` command — purges old steps and ticks records, keeping only the last N days (default 30). Uses ID-based batch deletion for performance.

## 1.3.2 - 2026-04-10

### Fixes

- [BUG FIX] Fix MassAssignmentException in dispatcher failsafe — use direct property assignment instead of mass assignment for `can_dispatch` unlock

## 1.3.1 - 2026-03-30

### Fixes

- [BUG FIX] Restore `JustResolveException` — removed in 1.2.2 but still used by consuming packages for unrecoverable step failures

## 1.3.0 - 2026-03-19

### Features

- [NEW FEATURE] Add `steps:recover-stale` command — detects steps orphaned in Running state after worker process death and recovers them
- [NEW FEATURE] Reads each job's `$timeout` and `$retries` properties via reflection to determine stale threshold per step class
- [NEW FEATURE] Stale steps with remaining retries transition back to Pending; exhausted retries transition to Failed with diagnostic error message

## 1.2.2 - 2026-02-21

### Improvements

- [IMPROVED] Remove `JustEndException` and `JustResolveException` marker exceptions — functionally identical to each other, both just fell through to `reportAndFail`
- [IMPROVED] Simplify `isShortcutException()` to only check for `MaxRetriesReachedException`

## 1.2.1 - 2026-02-17

### Fixes

- [BUG FIX] Fix dispatcher groups configuration - use proper Greek letter groups (alpha through kappa) instead of generic group_1 through group_4

## 1.2.0 - 2026-02-14

### Features

- [NEW FEATURE] Add `BaseStepJob` abstract class — generic step orchestration foundation with lifecycle, retries, exception handling, and state transitions
- [NEW FEATURE] Add `FormatsStepResult`, `HandlesStepLifecycle`, `HandlesStepExceptions` traits for BaseStepJob
- [NEW FEATURE] Add `BaseDatabaseExceptionHandler` abstract with factory method and pattern-matching error classification
- [NEW FEATURE] Add `MySqlDatabaseExceptionHandler` for MySQL/MariaDB-specific transient, permanent, and ignorable error patterns
- [NEW FEATURE] Add `DatabaseExceptionHelpers` trait for database-agnostic exception classification
- [NEW FEATURE] Add `MaxRetriesReachedException`, `JustResolveException`, `JustEndException` marker exceptions
- [NEW FEATURE] Add hook methods (`externalRetryException`, `externalIgnoreException`, `externalResolveException`, `onExceptionLogged`) for extensibility by consuming packages
