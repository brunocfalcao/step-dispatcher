# packages/brunocfalcao/step-dispatcher — Project Guidance

Shared project instructions for Codex and Claude.

## Purpose and boundaries

Step-based job execution system with state machines for reliable, sequential task processing

## Documentation

Start at `../../../docs/step-dispatcher/README.md`, then read the relevant architecture, product, and feature documents. Verify their claims against this checkout.

## Working rules

- Address Bruno directly and use concise caveman full communication; code, commits, and PR descriptions remain professional language.
- Read relevant source, callers, configuration, tests, and scoped instructions before editing. Current code wins over unsupported documentation; planned and historical behavior must be labeled.
- Follow the closest working implementation. Keep changes within Bruno's requested product behavior; preserve unrelated dirty work and user data.
- Bruno owns product decisions. The agent owns technical choices. Ask about material product ambiguity, not class names or file placement.
- Never commit, push, deploy, send messages, or perform destructive operations unless the task authorizes them. Never expose credentials.
- Run the smallest relevant existing executable checks. Ordinary implementation defers new coverage to the release pass; authentication, privacy, billing, data integrity, concurrency, and production regressions require immediate coverage. Do not claim browser/device/provider acceptance without observing it.
- Resolve shared `do` workflows from `~/Herd/.dynamic-commands/` freshly. Read applicable global rules and project/nested instructions. Keep handoffs concise and outcome-first.

## Dependency contract

Manifest requirements: PHP `^8.2`, `illuminate/support` `^11.0|^12.0|^13.0`. Exact resolved versions come from `composer.lock`; runtime versions require a fresh check.

## Project constraints

- Preserve persisted state transitions, group locking, ordering, recovery, retries, and idempotency. Inspect the closest package tests before changing lifecycle behavior. The package now owns a Pest/Testbench suite; the old host-only test instructions are obsolete. Public dependency compatibility and the development test stack are separate contracts.
- Stale recovery honors `BaseStepJob::timeoutSeconds()` for configured job budgets. Its default `null` preserves reflected property defaults; nonpositive overrides retain the existing 300-second fallback. The 60-second recovery buffer and retry transitions are unchanged.

- Stale-running recovery retains the original scan snapshot for alert timing;
  retry transitions clear the persisted start timestamp. Recovery must process
  multiple stalled Steps without dereferencing the reset timestamp.

- Dispatched recovery inspects pending, delayed and reserved Redis payloads
  when the driver supports inspection. A payload still present in its worker
  lane retains its Step's state and retries. Inspection never restores jobs or
  models, matches the runtime table prefix, and defers recovery when Redis or
  a Step payload cannot be inspected. Other drivers retain their existing path.

- The critical stuck alert means Dispatched recovery already rescued every step
  in the batch, judged by the note each rescue leaves in `error_message`. Retries
  are no proof, because ordinary retries count them too. A job that overwrites the
  note makes a repeat report as a warning. A first stuck step with no retries left
  fails and reports a warning.
- Parent completion rechecks failed/stopped descendants even after the
  failure-propagation pass, including completion from the job lifecycle. A
  concurrent child failure must propagate before downstream work can advance.
- A worker's post-run writes require it to still own the row. Every write in
  the job lifecycle (`response`, duration, errors, retries, state) locks the
  row first and compares its state and attempt generation, the same check
  `failed()` makes. A worker whose step was recovered, stopped or cancelled
  while it ran writes nothing, runs no later consumer hook and logs one warning,
  while consumer `finally` blocks still run. A parent completed by the dispatcher
  sweep during its own attempt still stores its result. An attempt that moved the
  row itself, for example with `retryJob()`, finishes its run only while the row
  still shows that move. Two identical reschedules with equal retries remain
  indistinguishable. The check also runs before `executeJobLogic()`, `compute()`
  and the start hooks, so a step lost during a start check never begins its work.
  No such stop follows `executeJobLogic()`, because consumers release locks in
  `finalizeJobExecution()`; each step of that path is guarded instead. Consumer code
  that writes to `$this->step` directly is not covered by this check. A row lock
  is not exercised on SQLite, so `tests/Feature` proves the checks and
  `tests/Collision` proves the lock on real MySQL and Postgres.
- `Step::whileRowLocked` is the engine's rule for writes that decide on a state:
  it orders competing writers, adds no rollback, and ends when the new state is
  saved, through the model's `updated` event. A project's reaction then runs with
  the result committed. A failed statement before the state save aborts the lock's
  transaction on Postgres, so earlier saves are lost and the state does not move.
- After a failed guarded write the job's copy of the step goes back to the row, so
  a refused state is never written later and a reaction never fires twice. A saved
  outcome stands; a save that never reached the row ends the step Failed. A project
  transaction that undoes a saved state returns the step to the attempt.
- Skipped on a parent means everything inside it was skipped. `hasChildThatStarted`
  refuses the skip when any step inside, at any depth, reached Dispatched or a later
  working state, or was retried or rescheduled, and the parent stays Running.
  `started_at` cannot answer this, because a retry clears it.
  Nothing is dispatched or started under a Skipped ancestor. A child can be
  Dispatched for a moment under a parent that has just become Skipped; its worker
  is refused and moves it to Skipped.
- A dispatch with no current tick is its own generation: it clears `tick_id`, and
  only a payload with no tick may run against it. Two consecutive dispatches with
  no tick and equal retries remain indistinguishable. That needs a tick longer
  than the 5-minute cache entry or a cache flush, then a worker death and
  redelivery.

- Cooldown (`src/Support/Cooldown.php`) holds unstarted top-level blocks and
  never pauses bookkeeping. A started top-level block is atomic, descendants
  included. "Started" survives a return to Pending through `tick_id`,
  `retries` and `was_throttled`. Filtering happens in SQL before the per-tick
  cap. A missing `steps_cooldowns` table means no cooldown.

## Code map

`src/`, `tests/`. Inspect these entrypoints and their actual callers for the area being changed.

## Verification

Available entrypoints: `composer test`, `composer test:full`. Choose the narrowest relevant check; a listed full-suite, build, install, or packaging command is not an instruction to run it for every edit. Inspect test environment and side effects first.

`tests/Collision` contends separate PHP processes on the fixed database `step_dispatcher_collision_tests`: local MySQL by default, Postgres with `STEP_DISPATCHER_COLLISION_DRIVER=pgsql`. It skips with the reason when no server is reachable. Run one at a time per server, and on both servers after changing a lock or a state save. Mutation checks belong in a scratch copy, because local apps symlink to this folder.

Orientation examples read: `src/StepDispatcherServiceProvider.php`, `tests/Feature/ActionableCascadeQueryBudgetTest.php`. Re-read the closest example for the actual task rather than copying an unrelated one.

## Keep this file current

Please update `PROJECT.md` whenever project guidelines, architecture, workflows,
or verified constraints change. Correct documentation that is not supported by
current codebase evidence; codebase wins. Distinguish implemented behavior from
plans and historical records. Keep `AGENTS.md` and `CLAUDE.md` as thin pointers
to this shared file so Codex and Claude follow the same project guidance.
