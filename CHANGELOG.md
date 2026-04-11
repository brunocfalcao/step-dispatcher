# Changelog

All notable changes to this project will be documented in this file.

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
