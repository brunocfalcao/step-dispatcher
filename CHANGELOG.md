# Changelog

All notable changes to this project will be documented in this file.

## 1.14.0 - 2026-06-13

### Features

- [NEW FEATURE] **Database-engine portability — unknown engines no longer crash every job.** `BaseDatabaseExceptionHandler::for()` previously `throw`ew on any engine without a tuned handler, and because it resolves inside `prepareJobExecution()` that exception killed every step *before* `compute()` ran — so a consumer app on an unsupported driver had a fully wedged dispatcher. It now returns a pattern-less `GenericDatabaseExceptionHandler` (classifies nothing as retryable/ignorable/permanent, so DB exceptions fall through to the job's normal handling) and ships a dedicated `SqliteDatabaseExceptionHandler`, so the test suite and sqlite-backed consumers exercise the real exception path instead of a stub.
- [NEW FEATURE] **`Timing` support helper** centralises the `microtime(true)` → whole-millisecond elapsed calculation shared by the job, dispatcher, and tick recorder, removing three subtly-different rounding sites.
- [NEW FEATURE] **`InteractsWithStepTrees` command concern** extracts the shared tree-walk logic out of the Archive and Purge commands (each shed ~100+ lines of duplicated traversal), so tree-safety behaviour is defined once and the two commands can't drift.

### Bug fixes

- [FIXED] **`recover-stale` and `archive` on PostgreSQL** now quote the reserved identifiers `group` / `index` / `queue` through the query grammar instead of interpolating them raw, so those commands stop erroring on pgsql consumers.

### Hardening

- [IMPROVED] Broad robustness pass with new regression coverage: dispatch-claim race safety, parent-completion and stopped-parent guards, cancelled-step cascade, falsy `compute()` result handling, group-scope isolation, archive/purge tree-safety parity, dispatchability parity, group round-robin fairness, recover-stale retry-budget guard, dispatch-command exit codes, and a hot-path query budget — 115 tests pass.

## 1.13.5 - 2026-06-09

### Bug fixes

- [FIXED] **Group-progress watchdog (`steps:recover-stale --watchdog-progress`) no longer pages on freshly-arrived work in sparse, event-driven groups.** `detectGroupNoProgress` decided a group was stalled from two signals only — a non-empty non-throttled `Pending` tally and a last terminal-state `updated_at` older than the threshold — and never considered how long the pending work had *itself* been waiting. For groups fed at long, irregular intervals (Kraite's `trading_*` set, driven by Binance user-data WebSocket events that arrive hours apart), the previous terminal step is routinely older than the threshold the instant a new step is created, so the only missing precondition was a `Pending` step existing at read time. When the every-minute watchdog tick happened to read a step in the ~1-second window between its creation (`Pending`) and its dispatch, all conditions were briefly true and the watchdog fired a CRITICAL `group_no_progress` page on work that completed a second later. Observed 2026-06-09 on athena: `trading_steps` group `gamma` — a `ProcessUserDataEventJob` created at 18:00:01, completed at 18:00:02, paged as "wedged, no progress for 72 minutes" because the group's prior event had landed at 16:48:38. The Pending tally now also carries `MIN(created_at)`, and a group only trips the watchdog once its oldest non-throttled Pending step has itself been waiting past the threshold. A genuine wedge holds steps `Pending` well beyond the cutoff, so it still fires exactly as before; the 2026-04-25 16h-wedge shape (old unpromotable Pending steps) is unaffected. One regression test added to `GroupProgressWatchdogTest` (a brand-new Pending step in a group with a stale last terminal stays silent); the two genuine-wedge tests now age their Pending step to model a real stall.

## 1.13.4 - 2026-06-08

### Bug fixes

- [FIXED] **Group-progress watchdog (`steps:recover-stale --watchdog-progress`) no longer pages on throttle-induced backpressure.** `detectGroupNoProgress` counted *every* `Pending` step toward the "group can't drain" signal, including steps that had rescheduled themselves under a rate limiter (`is_throttled=true`, `dispatch_after` in the future, `updated_at` bumped on every retry bounce). Those steps are progressing — they simply cannot reach a terminal state until the API window reopens — so a group saturated by chronic TAAPI / exchange 429s showed no terminal progress for the threshold window and fired a phantom `group_no_progress` alert that always self-recovered the moment the window cleared (observed on Kraite's athena box: gamma/kappa/epsilon groups flapping under ~1000 TAAPI `/bulk` 429s per hour). The Pending tally now excludes `is_throttled` rows, so only genuinely-dispatchable-but-not-progressing work can trip the watchdog, and `pending_count` in the emitted event reflects the true stuck count. A wedged group holding non-throttled Pending work still fires exactly as before. `is_throttled` is indexed, so the added predicate is free. Two regression tests added to `GroupProgressWatchdogTest` (throttled-only group stays silent; mixed group fires counting only the non-throttled work).

## 1.13.3 - 2026-06-05

### Bug fixes

- [FIXED] **`StepObserver::saving()` no longer rewrites `queue='priority'` on every save of a `priority='high'` step — creation-time only.** The unconditional rewrite fired during the save that persists the queue-resolver's physical name inside `dispatchSingleStep`, clobbering it back to the logical `priority` queue right before the Redis push. In consumer apps whose physical queue names diverge from the logical names (Kraite's `{hostname}-{logical}` convention since its v1.53.0), every high-priority workflow — position closes, order corrections, recover-stale promotions — was pushed onto a queue no worker subscribes to and stranded. Post-creation the queue column is owned by the registered queue resolver. Caught 2026-06-05 during Kraite's first live trading smoke test. Two regression tests added to `QueueResolverHookTest` (resolver value survives the dispatch save on priority='high' steps; creation-time auto-route still applies).

## 1.13.0 - 2026-05-25

### Features

- [NEW FEATURE] **`StepDispatcher::setQueueResolver(callable)` extensibility hook.** Consumer apps can register a closure that decides the physical queue a step should land on at dispatch time. The dispatcher invokes the closure in `DispatchesJobs::dispatchSingleStep()` before pushing to Redis. Contract: `(Step) → ?string`. Returning a non-null string overrides `step.queue` AND persists the new value to the row (queryable, observable in Horizon, retained on retry). Returning `null` means "no opinion" — the framework uses `step.queue` verbatim. Throwing `NoCleanWorkerException` propagates to the outer try/catch, which transitions the step to `Failed` and records the exception message on the row. Sync steps (`queue=sync`) bypass the resolver entirely. Optional: when no resolver is registered, the framework behaves exactly as it did pre-1.13.0 (covered by the pre-existing 63-test suite staying green). Designed for ban-aware IP routing in consumer apps like Kraite.
- [NEW FEATURE] **`NoCleanWorkerException`** under `StepDispatcher\Exceptions\`. Used by a registered queue resolver to signal "no eligible worker for this step" as a terminal failure. The dispatcher catches it via the existing try/catch path → step transitions to `Failed`. Side effects (notifications, account state changes, audit logs) are the resolver's responsibility BEFORE throwing — the framework does not interpret the exception beyond the standard "cannot dispatch" semantic.

### Removed

- [REMOVED] **`HandlesStepLifecycle::rotateToQueue()`.** Shipped in v1.12.3 and removed in this release. The rotation-by-queue-name approach conflicted with the priority promotion path (`RecoverStaleStepsCommand` re-routing stuck Dispatched steps to `queue=priority` overrode the per-hostname affinity, breaking IP isolation). The successor mechanism is the dispatch-time `setQueueResolver()` hook, which moves the routing decision earlier in the lifecycle (before push) where priority and IP affinity don't collide.

## 1.12.2 - 2026-05-13

### Fixes

- [BUG FIX] **`shouldDoubleCheck()` exhausted budget now fails the step instead of silently completing.** Pre-fix, two `doubleCheck() === false` returns fell through `needsVerification()` → `finalizeJobExecution()` → `shouldComplete()`, marking the step `Completed` even though verification never returned true. Unsafe for exchange-facing jobs where `doubleCheck()` is the only confirmation that an order was accepted. Now transitions to `Failed` with explanatory `error_message` so the parent's resolve-exception path runs.
- [BUG FIX] **`retryJobWithBackoff()` now passes the computed dispatch time directly to `retryJob()`** so the database-saturation/deadlock backoff isn't overwritten by `retryJob()`'s own dispatch-time resolution. Pre-fix, the database handler's exponential delay was computed, written to `dispatch_after`, then immediately overwritten — producing a tighter retry loop than intended during DB stress.
- [BUG FIX] **`StepDispatcher::buildStepsCache()` now scopes parent / sibling / resolve-exception lookups by `$group`** when one is supplied. Pre-fix, group-scoped pending-step selection could evaluate transitions against rows from other groups via shared block UUIDs.

### Improvements

- [IMPROVED] **`StepDispatcher::batchTransitionSteps()` logs failed transitions** (step id, current/target state, group, class, exception) to the `jobs` channel instead of swallowing them. Pre-fix, a stuck step would surface on the dashboard with no breadcrumb explaining why the cleanup phase missed it.

## 1.12.0 - 2026-05-08

### Features

- [NEW FEATURE] **Table-prefix isolation (`--prefix=<name>`).** The dispatcher can now run as N completely isolated ecosystems against the same database. Each ecosystem owns its own four-table set (`{prefix}steps`, `{prefix}steps_dispatcher`, `{prefix}steps_dispatcher_ticks`, `{prefix}steps_archive`) with prefix-interpolated index names. Same dispatcher code runs against any prefix — `RuntimeContext` (a scoped singleton) holds the active prefix on a stack; every Step / dispatcher query resolves its table via `tableName()` helpers that read that stack.
- [NEW FEATURE] **`Steps::usingPrefix($prefix, $closure)` facade method** for closure-scoped pushes (push on entry, pop in finally — a throw inside the closure still balances the stack). Plus `Steps::normalise($prefix)` for the trailing-underscore canonicalisation (`'trading'` → `'trading_'`).
- [NEW FEATURE] **`Step::prefix($name)->create([...])` builder** for single-call explicit overrides — lets host code fan out across both ecosystems from the same call site without nesting `usingPrefix` blocks.
- [NEW FEATURE] **`steps:install --prefix=<name>` artisan command** creates the four prefixed tables programmatically with prefix-interpolated index names. Per-table idempotent: existing tables are skipped, missing ones created (re-run heals partial drops). The dispatcher group seed (alpha..kappa) only fires when the dispatcher table is genuinely created so re-runs cannot duplicate seeded rows. Empty prefix is rejected (that's what the package's stock migrations install).
- [NEW FEATURE] **Universal `--prefix=` CLI option** injected by `BaseCommand::__construct()` onto every subclass after Laravel's signature-parsing pass. The option pushes / pops via `RuntimeContext` for the duration of `execute()`.

### Improvements

- [IMPROVED] **`BaseStepJob::__unserialize()` overridden** to push the prefix from the raw payload BEFORE Laravel's `SerializesModels` trait restores the `$step` model. Without this gate, the trait runs `Step::find($id)` deserialize-time against the default table for a row that lives in `{prefix}steps`, fails with `ModelNotFoundException`, and every prefixed job lands in `failed_jobs`. `handle()` and `failed()` re-push the prefix for their own execution bodies, balanced by `pop()` in finally.
- [IMPROVED] **`DispatchesJobs::dispatchSingleStep()` stamps `$job->stepPrefix`** with the ambient prefix at queue time so the serialised job payload travels with the prefix. Worker-side propagation guarantees the chain (parent → child via `Step::create()` inside `compute()`) inherits the ambient prefix end-to-end.
- [IMPROVED] **All cron commands prefix-aware end-to-end.** `DispatchStepsCommand`, `RecoverStaleStepsCommand`, `ArchiveStepsCommand`, `PurgeStepsCommand`, `InstallPrefixedTablesCommand` resolve every source / destination table through the active prefix. The recursive child-block CTE in `StepDispatcher::collectAllNestedChildBlocks()` interpolates `Step::tableName()` instead of the literal `FROM steps`. Raw `INSERT INTO steps_archive ... SELECT ... FROM steps` in `ArchiveStepsCommand` rebuilt to use both `StepsArchive::tableName()` and `Step::tableName()`.
- [IMPROVED] **Per-prefix flag file path** — `StepDispatcher::activate/deactivate/isActive` writes `{flag_dir}/{prefix}active.flag` so a prefixed dispatcher going idle does NOT deactivate other prefixes (the previous shared `active.flag` was a cross-prefix coupling hazard).
- [IMPROVED] **All cache keys carrying group-scoped state are now prefix-scoped:** `current_tick_id:{prefix}{group}`, `steps_dispatcher_tick_start:{prefix}{group}`, `dispatcher:saturation:{prefix}{group}:{bucket}`. Two prefixed dispatchers sharing a group name (alpha, beta, …) never stomp each other.
- [IMPROVED] **`Step::scopePending` model-aware** — reads `$query->getModel()->getTable()` instead of the literal `'steps.state'` alias, so the scope works under any prefix.
- [IMPROVED] **New `StepsArchive` Eloquent model** with the same `getTable()` / `tableName()` resolution as `Step`. Used by `ArchiveStepsCommand` to compose the destination table name without string-literal hardcodes.

### Tests

- [NEW FEATURE] **29 new prefix-only tests** under `tests/Feature/Prefix/`: `RuntimeContextTest`, `PrefixIsolationTest` (incl. idempotent installer + heal-pass cases), `PrefixCacheKeysAndFlagTest`, `PrefixRecursiveCteTest`, `PrefixWorkerPayloadTest`, `PrefixedArchivePurgeTest`, `PrefixedTickFullLifecycleTest`. Global `afterEach(RuntimeContext::reset())` in `tests/Pest.php` keeps any test that forgets to pop from polluting the next. Whole package suite at default prefix `''`: 60 green (31 original + 29 new).

## 1.11.14 - 2026-05-08

### Improvements

- [NEW FEATURE] **Per-tick saturation counters via `StepDispatcher::recordTickMetrics()`.** Every tick increments four Redis counters keyed by `(group, UTC minute bucket)`: `ticks_observed`, `ticks_capped` (dispatchable_count == max_per_tick), `ticks_capped_with_leftover` (capped AND Pending after promotion > 0), `total_dispatched`. Plus a `max_pending_after` running max. The counters are wrapped in a try/catch — telemetry must never break dispatch. A host-app cron is expected to flush the previous minute's keys into a persistent table for dashboard surface; the dispatcher hot path stays Redis-only and pays only sub-millisecond INCRs per tick. Saturation % per bucket = `ticks_capped_with_leftover / ticks_observed`. Sustained 100% across all groups = unambiguous signal that more dispatcher capacity (more groups) would help; sub-100% means the cap is not the bottleneck.

## 1.11.13 - 2026-05-07

### Fixes

- [BUG FIX] `StepDispatcher::dispatch()` priority pass-1 fall-through. Previously, when `priority='high'` Pending rows existed but NONE of them were dispatchable this tick (e.g. an orphan priority step whose previous index is missing — a poison pill), the dispatcher skipped pass 2 entirely and the entire group's non-priority backlog starved. Pass 2 now runs whenever pass 1 produces zero *dispatchable* work, not only zero rows fetched. Production trigger (2026-05-07, group `eta`): one undispatchable `priority='high'` step (`UpdatePositionStatusJob` at `index=9` in a block with no `index=8` row) wedged 660+ non-priority Pending rows for 11+ minutes before the group-stall watchdog fired.
- [IMPROVED] Extracted `StepDispatcher::buildStepsCache()` so the priority and non-priority passes share a single cache-construction helper instead of duplicating the three-query block. Pure refactor — same N+1 protection, same semantics.

### Tests

- [NEW FEATURE] `tests/Feature/PriorityFallthroughTest.php` — pins the contract that an undispatchable `priority='high'` step does not starve a dispatchable non-priority backlog. Constructs an orphan poison pill at `index=2` with no `index=1` and three dispatchable non-priority orphans; expects the poison pill to stay Pending and all three non-priority steps to be promoted in the same tick.
- [BUG FIX] `tests/Feature/NotRunnableToCancelledTransitionTest.php` now sets `step-dispatcher.flag_path` in `beforeEach` so `StepDispatcher::dispatch()` no longer throws `RuntimeException` mid-test. Same pattern as every other feature test in the suite.

## 1.11.12 - 2026-05-01

### Improvements

- [IMPROVED] `StepDispatcher::dispatch()` now uses two-pass selection: pass 1 fetches ALL `priority='high'` Pending steps with no per-tick cap, pass 2 falls back to the capped non-priority FIFO only when no priority work exists. Previously, a `priority='high'` step inserted after a large non-priority backlog could land outside the `max_per_tick` fetch window and stay invisible to the dispatcher — defeating the whole point of priority routing. Production trigger (2026-05-01): a 1700-row hourly leverage-bracket batch buried an observer-dispatched `PrepareOrderCorrectionJob` for ~8 minutes. The cap still bounds non-priority workloads.
- [IMPROVED] `StepObserver::saving()` now propagates `priority='high'` from a parent step to its child block. When a new step's `block_uuid` matches an existing parent's `child_block_uuid` and the parent is `priority='high'`, the new step inherits `priority='high'` (unless it explicitly set its own value — explicit always wins). Without this, priority routing was one-step-deep: a `priority='high'` parent's spawned children fell back to `priority=null` and joined the normal FIFO group, stalling the rest of the workflow chain. Production trigger (2026-05-01): an observer-dispatched `PreparePositionReplacementJob` (priority='high') spawned `VerifyPositionExistsOnExchangeJob` and `SmartReplaceOrdersJob` children at `priority=null`; the latter landed in group `beta` behind 150 pending non-priority steps and stalled the SL recreation.

### Tests

- [NEW FEATURE] `tests/Feature/PriorityBypassesTickLimitTest.php` — pins the contract that `priority='high'` steps are promoted regardless of `max_per_tick` and FIFO position, plus a sibling regression test that non-priority workloads still respect the cap.
- [NEW FEATURE] `tests/Feature/PriorityInheritanceTest.php` — pins inheritance: child inherits `priority='high'` from a priority parent, non-priority parent does not propagate, explicit child priority overrides inheritance, and propagation chains through multi-level workflows (root → child → grandchild).

## 1.11.11 - 2026-05-01

### Improvements

- [IMPROVED] `step-dispatcher.queues.valid` now includes `'user-data-stream'`. Without the entry, `StepObserver::saving()` silently rewrites unknown queue values to `'default'`, which buried the new Binance user-data Steps behind the unrelated default-queue backlog. Generic addition — any host app on the package that wants a per-feature queue can keep extending this list.

## 1.11.10 - 2026-04-29

### Improvements

- [IMPROVED] Per-tick step selection now orders strictly by `id ASC` (FIFO), dropping the prior `is_throttled ASC` lead key. Without an explicit `id ASC`, MySQL was returning the same deterministic primary-key sample every tick, so any ungated cluster at the front of the table could never get sampled when those slots were filled by `canTransition()=false` siblings. The comment now documents the intent — explicit FIFO, no implicit primary-key ordering reliance.

## 1.11.9 - 2026-04-29

### Improvements

- [IMPROVED] Per-tick step selection now orders non-throttled rows ahead of throttled ones (`orderBy('is_throttled')` then `orderBy('id')`). Without it, a large pile of throttled rows from a single rate-limited external API (e.g. 5,000 Apollo steps waiting on a window reset) could monopolise the per-tick LIMIT sample and starve unrelated work. Within each throttle bucket, oldest-first preserves FIFO fairness.

## 1.11.8 - 2026-04-29

### Improvements

- [IMPROVED] Added `@property` docblock to `StepsDispatcherTicks` (id, group, progress, started_at, completed_at, duration, created_at, updated_at). Resolves Larastan level-max access on the `duration` column from downstream callers (e.g. `StepsDispatcher::recordTickWhen` closures in app providers).

## 1.11.7 - 2026-04-28

### Features

- [NEW FEATURE] `steps:purge --only-archive` flag — date-based delete on `steps_archive` only, leaves the live `steps` table and ticks untouched. Designed as the cooled-down companion to `steps:archive`: archive moves terminal trees daily, this purge eventually drops them from archive once they're past the retention window. Because `steps_archive` is populated only by `ArchiveStepsCommand` (which guarantees every row is part of a fully-terminal tree), no tree-walk safety check is needed — flat date-based delete is correct. Default mode (no flag) is unchanged.

### Tests

- [NEW FEATURE] `tests/Feature/PurgeOnlyArchiveTest.php` — 5 cases pinning the new contract: deletes archive rows older than `--days`, leaves live `steps` table untouched, leaves ticks untouched, validates `--days >= 1`, regression guard that default mode still walks the live table.

## 1.11.6 - 2026-04-25

### Fixes

- [BUG FIX] `StepDispatcher::skipAllChildStepsOnParentAndChildSingleStep()` — phase 0 of the dispatcher tick now returns `true` ONLY when at least one descendant was actually transitioned. Two `return true` paths previously fired on no-op outcomes: (a) parent's child-block resolves to no descendants, and (b) batch transition runs against descendants that are all already in terminal states (rejected by the state machine, swallowed silently). Each false-positive return blocks the dispatch phase for the rest of the tick; under load this manifests as a complete per-group wedge, exactly the second wedge class in the 2026-04-25 production incident (eta / beta / iota / kappa stalled ~16h on Skipped parents whose `child_block_uuid` pointed at a fully-terminal child block).
- [BUG FIX] `StepDispatcher::promoteResolveExceptionSteps()` — same return-true-on-no-op shape, racier trigger. The candidate-blocks scan and the resolve-exception step-id pluck are separate queries; a parallel tick / worker can promote the resolve-exception between the two. The phase now returns `false` when `$stepIds` ends up empty so the dispatch phase still runs.

### Features

- [NEW FEATURE] `step-dispatcher.dispatch.max_per_tick` config (env: `STEP_DISPATCHER_MAX_PER_TICK`, default `100`) — caps how many Pending rows a single tick hydrates per group. Without the cap, a group with thousands of Pending rows (wedge state, traffic spike) loaded all of them every second, blew the tick budget, and starved sibling groups. Drains in waves; consistent. Set to `0` to disable.
- [NEW FEATURE] `RecoverStaleStepsCommand --watchdog-progress` (with `--progress-threshold=600`) — generalised stall detection beyond per-step zombies. Per group, if there are Pending steps but no terminal-state step has been updated within the threshold, fires a `group_no_progress` `StaleStepsDetected` event with severity=critical. Catches cleanup-phase wedges that don't surface a stuck step (the failure mode that hid the 2026-04-25 wedge for 16h while the existing detector saw nothing).

### Tests

- [NEW FEATURE] `tests/Feature/CleanupPhasesProgressTest.php` — pins the cleanup-phase contract: phase 0 must return `false` when the Skipped parent's child block is empty AND when every descendant is already terminal. Source-level guard against `promoteResolveExceptionSteps` regressing back to a bare `return true` after `batchTransitionSteps`.
- [NEW FEATURE] `tests/Feature/DispatcherTickLimitTest.php` — pins the per-tick load-shedding contract via a 5-step fixture with `max_per_tick=2`.
- [NEW FEATURE] `tests/Feature/GroupProgressWatchdogTest.php` — pins the group-progress watchdog: stalled groups fire the event, idle groups (zero Pending) do not.

## 1.11.5 - 2026-04-25

### Tests

- [IMPROVED] `tests/Feature/ParentResolutionContractsTest` — added two regression tests pinning the parent-resolution contract that consumers must respect: (Z1) a parent with `child_block_uuid` set but zero children stays Running forever — locks the framework's intentional NOT-concluded behavior so a future "auto-conclude empty blocks" loosening is caught (it would silently mask consumer-side zombies); (Z2) `Step::makeItAParent()` persists the generated UUID on the row AND returns it — locks the helper round-trip so a refactor that returns the UUID without writing it can't recreate the zombie pattern from a different angle.

## 1.11.4 - 2026-04-23

### Fixes

- [BUG FIX] `StepsDispatcher::startDispatchCriteria()` — merged the stale-lock failsafe reset and the CAS acquire into a single atomic `UPDATE ... WHERE can_dispatch=true OR (can_dispatch=false AND updated_at < now-20s)`. Previous two-step path let two concurrent ticks both pass the failsafe check and overlap inside the dispatch critical section.
- [BUG FIX] `PurgeStepsCommand` — purge is now tree-aware. Only deletes root blocks whose entire descendant tree is in a terminal state; long-running workflows with half-concluded children are no longer orphaned mid-tree.
- [BUG FIX] `RecoverStaleStepsCommand::recoverStaleRunningSteps()` — clears `is_throttled` before transitioning `Running → Pending`, so worker-death recovery consumes a retry even when the step was flagged throttled. Without this, a step that kept timing out while throttled could recover forever without ever exhausting its retry budget.
- [BUG FIX] `RecoverStaleStepsCommand::requeueDispatchedSteps()` — refreshes each step from DB inside the loop and skips if no longer in `Dispatched` state. Closes the race where a worker picks up a Dispatched step between the query snapshot and the transition call.
- [BUG FIX] `StepDispatcher::transitionParentsToComplete()` — catch block now logs to `Log::error` with step context (id, class, block_uuid, child_block_uuid, group, exception class, message) instead of silently swallowing. Operators can now grep for stuck-parent transition failures.
- [BUG FIX] `Step::childStepsAreConcluded()` / `childStepsAreConcludedFromMap()` — now treat every terminal child state (`Completed`, `Skipped`, `Cancelled`, `Failed`, `Stopped`) as "parent may leave Running". Previously only `Completed`/`Skipped` qualified, leaving parents with all-Cancelled children stuck `Running` forever since no later pass ever revisited the decision.

### Tests

- [NEW FEATURE] `tests/Feature/StartDispatchAtomicLockTest.php` — asserts the stale-lock recovery + CAS acquire happens in a single UPDATE.
- [NEW FEATURE] `tests/Feature/PurgeStepsTreeSafetyTest.php` — four tree-safety scenarios covering half-concluded children, deep descendant trees, and mixed-age roots.
- [NEW FEATURE] `tests/Feature/RecoverStaleThrottledRetryBudgetTest.php` — asserts `retries` advances and `is_throttled` clears when recovering a stale throttled Running step.
- [NEW FEATURE] `tests/Feature/RequeueDispatchedRaceSafetyTest.php` — uses the `Step::retrieved` event to simulate a worker grabbing the step mid-requeue; asserts the refresh-in-loop skips it.
- [NEW FEATURE] `tests/Feature/ParentResolutionContractsTest.php` — two contracts: transition exceptions reach `Log::error`, and parents with all-Cancelled children leave `Running` within one tick.

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
