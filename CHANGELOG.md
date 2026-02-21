# Changelog

All notable changes to this project will be documented in this file.

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
