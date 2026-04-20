# Changelog

All notable changes to this project will be documented in this file.

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
