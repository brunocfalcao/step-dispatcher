# step-dispatcher

A database-backed workflow orchestrator for Laravel queues. Each step is an
Eloquent row with a state, inputs, result, retry counter, and workflow links.
The dispatcher claims eligible rows; Laravel workers run their job classes.

```text
Step::create() -> Pending -> Dispatched -> Running -> Completed
                            Laravel queue            or another outcome
```

## Capabilities

| Capability | What the package provides |
|---|---|
| Tracked execution | Live step rows, timing, response, errors, exception triage, and optional diagnostic files |
| Ordered and parallel work | Shared `block_uuid`; consecutive indexes sequence stages, equal indexes allow parallel work |
| Nested workflows | Parent/child blocks, parent completion gates, failure/stop propagation, cancellation and skip sweeps |
| Recovery work | Dormant `resolve-exception` steps promoted when their block fails or stops |
| Lifecycle hooks | Start guards, computation, double checking, completion confirmation, and exception policies |
| Retry and throttle handling | Counted retries, configurable backoff, and rescheduling without consuming a retry |
| Dispatch coordination | Seeded groups, atomic group locks and step claims, priority dispatch, and a normal-candidate cap |
| Queue routing | Creation defaults plus an optional resolver called on each asynchronous dispatch attempt |
| Isolation | Four tables per prefix, scoped runtime context, separate activity flags and dispatcher cache keys |
| Operations | Stale-worker recovery, dispatched recovery, lock release, progress alerts, archive and purge commands |
| Observation | Workflow status, tick retention filters, slow-tick callbacks, and best-effort cache counters |

These rows are mutable execution records, **not an append-only history of every
attempt**. Retries reset timing fields; a previously stored response is retained.
Archive and purge also affect how long records remain available.

Atomic claims coordinate competing dispatchers. They do **not** guarantee
exactly-once external effects: recovery can run a job again after an earlier
worker performed an effect. Make repeatable business operations idempotent.
The package does not provide a UI, notification transport, scheduler daemon,
worker manager, benchmark guarantee, or automatic external API throttler.

## Compatibility

[Composer](composer.json) declares:

- PHP `^8.2`.
- Illuminate Support, Database, Console, and Queue `^11.0|^12.0|^13.0`.
- `spatie/laravel-model-states` `^2.0`.

The current development suite uses Laravel 13 through Testbench 11 and Pest 5,
with SQLite in memory. Database-specific exception handlers cover MySQL/MariaDB,
PostgreSQL, and SQLite; an unrecognized driver receives the generic handler.
This is not a claim that every supported framework/database combination has a
committed CI matrix. Validate your chosen engine's locking, recursive CTE,
timestamp, and queue behavior before relying on it in production.

Asynchronous work needs a configured Laravel queue connection and worker.
Horizon and Redis are host application choices, not package requirements.
Telemetry uses the configured Laravel cache store and tolerates failures.

## Installation

```bash
composer require brunocfalcao/step-dispatcher
php artisan migrate --no-interaction
```

Laravel discovers the service provider automatically. It loads package migrations
and registers the `Step` observer. Optional publishing:

```bash
php artisan vendor:publish --tag=step-dispatcher-config --no-interaction
php artisan vendor:publish --tag=step-dispatcher-migrations --no-interaction
```

The default activity-flag directory is `storage_path('step-dispatcher')` and is
created on activation. Applications dispatching from the same tables must share
this directory, or explicitly coordinate activation. The shipped configuration
does **not** read `STEP_DISPATCHER_FLAG_PATH`. To use that variable, publish the
configuration and map it yourself:

```php
'flag_path' => env('STEP_DISPATCHER_FLAG_PATH', storage_path('step-dispatcher')),
```

An empty configured path throws when flag access is attempted; it is not a
service-provider boot requirement. Database connectivity, filesystem access,
queue workers, and scheduling remain the host application's responsibility.

### Scheduling

Example host configuration in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('steps:dispatch')->everySecond()->runInBackground();
Schedule::command('steps:recover-stale')->everyMinute()->runInBackground();
Schedule::command('steps:archive --duration=5')->daily();
Schedule::command('steps:purge --only-archive --days=30')->daily();
```

Enable Laravel's scheduler separately. Laravel recommends background commands
for [sub-minute tasks](https://laravel.com/docs/13.x/scheduling#sub-minute-scheduled-tasks).
A requested cadence is not a latency guarantee: tick duration, dependencies,
worker availability, and queue backlog all affect execution time. Opt into the
additional recovery controls described below according to your workload.

## Create a step

```php
namespace App\Jobs;

use StepDispatcher\Abstracts\BaseStepJob;

class BuildReportJob extends BaseStepJob
{
    public int $retries = 3;
    public int $timeout = 60;

    public function __construct(public int $reportId) {}

    protected function compute(): array
    {
        // Replace with the host application's idempotent business operation.
        return ['report_id' => $this->reportId];
    }
}
```

```php
use App\Jobs\BuildReportJob;
use StepDispatcher\Models\Step;

$step = Step::create([
    'class' => BuildReportJob::class,
    'arguments' => ['reportId' => 42],
    'label' => 'Build report 42',
]);
```

The observer supplies a block UUID, Pending state, index 1, workflow UUID,
dispatch group, queue defaults, and the activity flag. Use Eloquent creation;
raw `insert()` bypasses these responsibilities and the non-null state has no
database default.

The dispatcher reflects the job constructor and matches `arguments` by
**parameter name**. Optional defaults are honored; missing required arguments
fail dispatch. This is not container dependency injection. Use JSON-compatible
inputs and resolve services inside the job when needed. The dispatcher attaches
`$this->step` and the runtime prefix before queuing the job.

`handle()` and `failed()` are final; implement `compute()` and optional hooks.
A positive public `$retries` property must be supplied by your job: the base
class does not declare one. Laravel queue `$tries` and the step retry counter
are separate controls. A positive public `$timeout` also informs stale recovery.

### Stored results and timing

`compute()` runs only while `double_check === 0`. The first non-null result is
stored if `response` is still null. `0`, `false`, `''`, and `[]` are retained;
null means no result. The default `formatResultForStorage($result): mixed`
returns the result unchanged. Override it for your payload format.

The protected `extractBodyFromResponse(ResponseInterface $response): mixed`
helper rewinds a seekable PSR response stream, decodes valid JSON, and otherwise
returns the body string. It is not applied automatically. For example:

```php
protected function formatResultForStorage(mixed $result): mixed
{
    return $result instanceof \Psr\Http\Message\ResponseInterface
        ? $this->extractBodyFromResponse($result)
        : $result;
}
```

`started_at`, `completed_at`, `duration` in milliseconds, and `hostname` describe
the current/latest run, not every attempt. Retrying resets run timing; entering
Pending clears the hostname. `failed(Throwable $e)` records queue-level failures
and only fails a row still Running or Dispatched; it preserves recovered or
terminal states and calls exception resolution for the row it fails.

## Step data and ownership

| Fields | Purpose |
|---|---|
| `class`, `arguments`, `label`, `type` | Job and inputs; default type is `default`, recovery type is `resolve-exception` |
| `block_uuid`, `index`, `child_block_uuid` | Sibling stage and nested child-block structure |
| `workflow_id`, `canonical` | Workflow identity and optional host-defined identifier |
| `relatable_type`, `relatable_id` | Polymorphic owner through `relatable()` |
| `state`, `execution_mode`, `double_check` | State machine and verification/confirmation progress |
| `queue`, `priority`, `group`, `tick_id` | Routing, dispatch lane, and originating tick |
| `retries`, `dispatch_after`, `was_throttled`, `is_throttled` | Retry count, due time, and throttle history/current flag |
| `response`, `error_message`, `error_stack_trace`, `step_log` | Result and diagnostic data |
| `exception_analysed`, `exception_verdict`, `was_notified` | Host-managed triage and notification metadata |
| `started_at`, `completed_at`, `duration`, `hostname`, timestamps | Execution and record timing |

On creation, workflow ID, group, and a complete owner pair can be inherited from
the parent, then a sibling. An explicitly supplied complete owner is retained.
A partial child owner is replaced by a complete inherited owner when available.
Children inherit a parent's high priority when their own priority is unset.
Explicit child priority wins. New independent work receives a workflow UUID and
round-robin group assignment when those are absent.

An optional job `relatable()` hook may return an Eloquent model to associate
when the run begins. This differs from creation-time inheritance.

## Ordered, parallel, and nested workflows

Steps sharing `block_uuid` form a block. Use consecutive indexes beginning at 1:
ordinary index 2 waits for all default-type index-1 siblings to be Completed or
Skipped (recovery work has its own sequencing rules). Equal indexes
allow parallel eligibility; actual worker concurrency depends on your queue.
Gaps in indexes can block work because the immediately previous index is checked.

A parent points `child_block_uuid` to the children's `block_uuid`. Indexed children wait
for their parent to be Running or Completed; null-index child handling requires
a Running parent. Normal Eloquent creation defaults a missing index to 1. The parent stays Running until its child block
concludes; an empty reserved child block is not considered concluded.

Example inside a parent job, using the `BuildReportJob` above:

```php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;

protected function compute(): void
{
    DB::transaction(function (): void {
        $childBlock = $this->step->child_block_uuid ?? (string) Str::uuid();

        if ($this->step->child_block_uuid === null) {
            $this->step->update(['child_block_uuid' => $childBlock]);
        }

        // Reuse existing children if this parent is retried.
        if (Step::where('block_uuid', $childBlock)->exists()) {
            return;
        }

        foreach ([41, 42] as $reportId) {
            Step::create([
                'class' => \App\Jobs\BuildReportJob::class,
                'arguments' => ['reportId' => $reportId],
                'block_uuid' => $childBlock,
                'index' => 1,
            ]);
        }
    });
}
```

This example reuses an existing child block; the host must still design any
external effects and concurrent workflow creation for its own idempotency needs.
`makeItAParent()` always assigns a **new** child UUID, so repeatedly calling it
can detach an earlier tree. A job's `uuid()` returns the existing child UUID or a
new UUID without storing it.

The dispatcher propagates failed/stopped child outcomes to parents, cancels
blocked later work and waiting descendants, and skips descendants of skipped
parents. These sweeps do not forcibly stop already Running jobs. Terminal queue
payloads are ignored when they eventually reach the worker. Nested block
collection uses a recursive CTE with a depth cap of 100; avoid cyclic graphs.

### Dormant recovery steps

Create a `type => 'resolve-exception'` step in the relevant block using Eloquent.
The observer gives it NotRunnable state. When the same dispatch group and block has a Failed or Stopped
ordinary step, the dispatcher can promote that resolver to Pending. Dormant resolvers
are ignored when determining successful parent completion. Resolver index 1
can start directly once promoted; later indexes depend on the preceding recovery
stage. Pending recovery work also changes which prior-stage type ordinary work
checks. Promotion makes a
resolver runnable; it does not erase the original failed row or turn the whole
workflow into a successful one. Use a valid job class and provide its inputs.

## Job lifecycle

After refreshing the step, the worker ignores terminal/already Running rows,
transitions to Running, starts timing, attaches the optional owner, and selects
the database exception handler.

For a normal run, guards are evaluated in this order. Only the exact return
value `false` rejects a guard; absent hooks allow the run.

| Optional hook | Effect when it returns false |
|---|---|
| `startOrStop()` | Stops the step |
| `startOrFail()` | Throws into the exception policy; fails unless that policy handles it |
| `startOrSkip()` | Skips the step |
| `startOrRetry()` | Counted retry, unless the hook already changed status/rescheduled it |

The retry budget is checked after those guards. Then `compute()` runs when
`double_check === 0`, followed by the optional verification hooks:

| Optional hook | Behavior |
|---|---|
| `doubleCheck()` | False increments the verification counter and retries; compute is not repeated on verification passes. Two false results cause the following ordinary verification pass to mark the step Failed directly (if the ordinary retry budget has not already stopped the run). Success marks verification with `double_check = 99`. |
| `confirmOrRetry()` | False switches to `confirming-completion` and returns to Pending. Later runs check confirmation without repeating compute. |
| `complete()` | Called before ordinary completion if no lifecycle control already changed status; its return value is ignored. |

A parent still waits for children instead of immediately completing.

### Retry budgets and confirmation

The ordinary budget check is `step.retries >= job.retries`. A budget of 3 permits
ordinary compute at counters 0, 1, and 2; counter 3 takes the exhaustion path.
A zero budget permits no ordinary compute. Guard hooks run before this check.
Do not interpret the budget as “initial run plus three retries.”

Confirmation mode bypasses the ordinary guards, budget check, compute, double
check, and `complete()` hook. A false confirmation calls `retryForConfirmation()`
again; Running-to-Pending still increments the counter, but no separate
confirmation limit or new backoff is imposed. Bound confirmation in host logic
if it may never succeed. Successful confirmation uses the parent completion gate.

### Explicit lifecycle controls

| Method available to a job | Effect |
|---|---|
| `retryJob($dispatchAfter = null)` | Pending, counted retry, clears current throttle flag, assigns backoff/due time, may raise priority |
| `rescheduleWithoutRetry($dispatchAfter = null)` | Pending, no retry increment, sets both throttle flags and a due time; may raise priority |
| `retryForConfirmation()` | Sets confirmation mode and returns to Pending; no new delay |
| `stopJob()` / `skipJob()` | Stops or skips and marks status handled |
| `reportAndFail(Throwable $e)` | Stores parsed diagnostics, finalizes timing, fails the step |
| `startDuration()` / `finalizeDuration()` | Timing helpers; finalization does nothing if execution never started |

Optional due times accept `Carbon`, `CarbonImmutable`, or null. Without an
explicit time, `retryJob()` and `rescheduleWithoutRetry()` use
`$jobBackoffSeconds = 10`, or positive `$jobBackoffMs` instead. Millisecond
computation is **best effort**: persisted precision depends on schema and ORM
date formatting, and execution depends on scheduler/worker cadence. The default
precision migration and prefixed installer differ; the model does not override
Eloquent's default second-format date serialization. No exact sub-second
execution guarantee is made.

Ordinary retry can set high priority once the current retry counter is at least
half a positive job budget. Override `shouldChangeToHighPriority(): bool` to
customize that policy and `getRetryDiagnostics(): array` for exhaustion details.
Priority escalation does not automatically change the physical queue.

Lifecycle controls do not interrupt PHP execution. Return from your business
code after calling one. Example of a host-owned throttle guard:

```php
protected function startOrRetry(): bool
{
    if (! $this->hostAllowsRequest()) {
        $this->rescheduleWithoutRetry(now()->addSeconds(2));

        return false;
    }

    return true;
}
```

`hostAllowsRequest()` is your application's check. The package supplies
rescheduling, not the throttling policy. Since v1.20.5, the false guard above
preserves the already handled reschedule without an extra counted retry.
`is_throttled` is cleared on a real Running transition; `was_throttled` persists.

## Exceptions

The ordinary exception policy checks:

1. `MaxRetriesReachedException`: resolution hooks, then failure if still unhandled.
2. Permanent database errors: report and fail.
3. Retry classification: database handler, job `retryException($e)`, then
   `externalRetryException(Throwable $e): bool`.
4. Ignore classification: job `ignoreException($e)`, then
   `externalIgnoreException(Throwable $e): bool`, then database handler.
5. Otherwise: log diagnostics, call `onExceptionLogged(Throwable $e): void`,
   run job `resolveException($e)` and `externalResolveException(Throwable $e): void`,
   and fail unless a lifecycle control marked the step handled.

An ignored ordinary step completes; an ignored parent still waits for children.
Resolution hook return values are ignored. Merely spawning fallback work does
not mark the original exception handled: use an appropriate lifecycle control
if the original should avoid the default failure. Queue-level `failed()` follows
the narrower fallback behavior described above.

Database handlers classify selected transient/permanent errors by their patterns.
Retry backoff for recognized database errors grows exponentially from the
handler's base delay and is capped by its maximum (generic defaults: 10 and 120
seconds). The package does not retry every database exception. Handler contracts
and implementations are in [BaseDatabaseExceptionHandler](src/Abstracts/BaseDatabaseExceptionHandler.php)
and [DatabaseExceptionHandlers](src/Support/DatabaseExceptionHandlers).

`ExceptionParser::with($e)` exposes `friendlyMessage()`, `errorMessage()`,
`className()`, `lineNumber()`, `filename()`, `stackTrace()`, `httpStatusCode()`,
`errorCode()`, and `errorMsg()` for structured diagnostics. The
`JustResolveException` and `NoCleanWorkerException` classes are available, but
are not special terminal states or automatic reschedule instructions. The main
job policy only special-cases `MaxRetriesReachedException`; a routing exception
is handled by dispatch failure handling.

## States and workflow status

The nine step states are Pending, Dispatched, Running, Completed, Skipped,
Cancelled, Failed, Stopped, and NotRunnable. Retries and throttling return to
Pending; they are metadata, not additional states.

The registered transitions in [StepStatus](src/Abstracts/StepStatus.php) are:

| From | Registered destinations |
|---|---|
| Pending | Dispatched, Running, Cancelled, Failed, Skipped |
| Dispatched | Running, Cancelled, Failed, Pending, Skipped |
| Running | Completed, Stopped, Failed, Skipped, Pending |
| NotRunnable | Pending, Cancelled, Skipped |
| Completed, Skipped, Cancelled, Failed, Stopped | None |

Registration is not a guarantee that every transition is currently eligible;
transition classes impose dependency and state guards. Prefer package lifecycle
methods and dispatch commands over arbitrary raw state updates.

`Step::concludedStepStates()` is Completed/Skipped.
`failedStepStates()` is Failed/Stopped.
`terminalStepStates()` is Completed/Skipped/Cancelled/Failed/Stopped.
`settledStepStates()` also includes NotRunnable.

```php
use StepDispatcher\Support\StepDispatcher;

$status = StepDispatcher::workflowState($step->workflow_id);
$value = $status->value;
$isFinished = $status->isSettled();
```

`workflowState()` examines **live rows in the current prefix**:

| `WorkflowState` | Meaning |
|---|---|
| Unknown | No live rows have this workflow ID, including after archival |
| Pending | Work has not started; required rows are still Pending |
| Running | Dispatched/Running work exists, or started work still has Pending rows |
| Failed | No active work remains and a row is Failed, Stopped, or Cancelled |
| Completed | Required work has concluded; dormant resolvers are ignored |

`isSettled()` is true only for Failed/Completed. Completed recovery work does not
remove a failed outcome while its original failed row remains.

## Groups, dispatch limits, and queues

Default tables seed ten groups: alpha, beta, gamma, delta, epsilon, zeta, eta,
theta, iota, kappa. Creation assigns absent groups using the dispatcher table's
round-robin selection. Group selection uses `FOR UPDATE SKIP LOCKED`; group tick
acquisition uses a separate atomic conditional update and can reclaim a lock
older than 20 seconds. Each Pending-to-Dispatched claim is also an atomic update.
Changing the configured list does not automatically reconcile existing rows.

A tick handles eligible high-priority rows first. It then performs cleanup
sweeps and normal distribution; a phase that changes rows may end that tick
before later phases. There is no separate “non-throttled first” pass.

Normal Pending candidates are due-time filtered, ordered by ID, and capped by
`dispatch.max_per_tick` (default 100, zero means unbounded). The cap limits
**hydrated candidates**, not guaranteed dispatches; blocked candidates can occupy
that window. High-priority candidates bypass this cap but still obey dependency
guards. Sibling/parent outcomes can require subsequent ticks to propagate.

At creation, the observer accepts the configured valid queues plus the lowercase
hostname and its dashless form. An absent/invalid queue defaults to `priority`
for a high-priority step, otherwise `default`; a valid explicit queue wins.
This normalization is creation-only.

### Queue resolver and synchronous execution

```php
use StepDispatcher\Models\Step;
use StepDispatcher\Support\StepDispatcher;

StepDispatcher::setQueueResolver(function (Step $step): ?string {
    return $step->priority === 'high' ? 'priority' : null;
});
```

A resolver is called on each asynchronous dispatch attempt, including after
retry. Null preserves the queue; a returned string is persisted as the physical
queue without creation-time normalization. The host must supply usable names
and workers. `getQueueResolver()` returns it; `setQueueResolver(null)` clears it.
Resolver, constructor, and queue submission exceptions fail a still Dispatched
row and record diagnostics, preserving a row already recovered elsewhere.

`queue = 'sync'` calls the job's `handle()` inline and skips the resolver.
`sync` is **not** in the shipped valid queue list: add it to your published
configuration before creating such steps. Other queues use Laravel
`Queue::pushOn()` and the configured queue connection.

## Prefix isolation

```bash
php artisan steps:install --prefix=trading --output --no-interaction
php artisan steps:dispatch --prefix=trading --output --no-interaction
```

```php
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

$step = Steps::usingPrefix('trading', fn () => Step::create([
    'class' => \App\Jobs\BuildReportJob::class,
    'arguments' => ['reportId' => 42],
]));
```

`trading` and `trading_` normalize to `trading_`; the empty string selects default
tables. Prefixes create independent `steps`, `steps_dispatcher`,
`steps_dispatcher_ticks`, and `steps_archive` tables, group rows, activity flags,
and dispatcher cache namespaces. Use trusted, valid database identifier prefixes:
normalization only adds an underscore and does not sanitize arbitrary input.

`Steps::usingPrefix()` returns the closure result and restores the previous
context in `finally`; nested calls are supported. `Steps::currentPrefix()` and
`Steps::normalise()` expose the current/normalized prefix. `RuntimeContext`
provides the scoped stack through `push()`, `pop()`, and `current()`, plus
`depth()` for diagnostics and `reset()` for test teardown. Queued jobs carry `stepPrefix`; restoration happens
before model deserialization, during `handle()`, and in `failed()`.

`Step::prefix('trading')` returns a query builder bound to that table; it does
**not** set ambient context for observers or related lookup helpers. Use
`Steps::usingPrefix()` for creation and workflow operations. The other table
models resolve the ambient prefix through their table helpers.

`steps:install` rejects an empty prefix, skips existing tables individually, and
seeds groups when it creates the dispatcher table. It is an installer, not a
schema upgrade/backfill tool or one all-or-nothing transaction. Keep existing
prefixed schemas aligned when upgrading. Diagnostic step log folders contain
only the numeric step ID and are not isolated by prefix.

## Commands

All five package commands accept `--prefix` and `--output`. They are silent by
default; `--output` enables their console messages, not a global Artisan option.
Normal Laravel options such as `--no-interaction` remain available.

| Command | Options beyond prefix/output |
|---|---|
| `steps:dispatch` | `--group=` |
| `steps:install` | Requires non-empty `--prefix=` |
| `steps:recover-stale` | `--recover-dispatched`, `--release-locks`, `--watchdog-progress`, `--step-threshold=300`, `--lock-threshold=30`, `--progress-threshold=600` |
| `steps:archive` | `--duration=5` days; minimum 1 |
| `steps:purge` | `--days=30` minimum 1, `--only-archive`, `--ticks` |

`steps:dispatch` exits successfully without work when the activity flag is
absent. Without `--group`, it visits groups present in the current dispatcher's
table (including a null row if present); an empty table falls back to the null
lane. Lists accept comma, colon, semicolon, pipe, or whitespace separators;
literal `null` selects SQL NULL. Unhandled command errors produce a nonzero exit.

Direct `StepDispatcher::dispatch(null)` targets **only the null group**, not all
groups. `hasActiveSteps()` checks Pending/Dispatched/Running;
`activeGroups()` returns named active groups. `activate()`, `deactivate()`, and
`isActive()` manage the current prefix's flag. Eloquent creation activates it;
raw database creation requires you to supply metadata and coordinate activation.

### Stale recovery and alerts

Every `steps:recover-stale` run checks Running candidates against `started_at`
plus the reflected job timeout (positive `$timeout`, otherwise 300 seconds) and
a 60-second buffer. This is elapsed-run detection, **not a heartbeat**. Parents
with committed children are protected from rerun; an empty child block is not.
The final state, age, and child checks are made under a row lock.

A recovered worker run consumes a genuine retry, clears throttle state, and
returns to Pending or fails at budget exhaustion. Recovery reflects a positive
job `$retries`, falling back to 2 if absent/non-positive; `$retries = 0` therefore
does not mean stale recovery can never requeue the row.

Additional controls:

- `--recover-dispatched`: checks Dispatched age via `updated_at`; recovery
  requeues at high priority on the **original queue**, with locked final checks
  and retry-budget enforcement. Repeated stalls can emit a critical alert.
- `--release-locks`: releases locks older than `--lock-threshold` seconds.
- `--watchdog-progress`: detects groups with pending work and insufficient
  recent terminal progress; uses `--progress-threshold` seconds and pending age.
- `--step-threshold` controls Dispatched age only; it does not replace the
  Running timeout calculation.

The `StaleStepsDetected` event exposes severity, reason, count,
`alreadyPromotedCount`, `promotedCount`, `releasedLocksCount`, `oldestStep`, and
context. Reasons include `stale_running_steps_recovered`, `stale_dispatched_steps_promoted`,
`stale_dispatched_steps_still_stuck`, `stale_dispatcher_locks_released`, and
`group_no_progress`.
Your host listener owns notification delivery and response.

### Retention

`steps:archive` selects old root blocks using their newest
`COALESCE(completed_at, updated_at)` timestamp, then checks the complete tree is
settled (terminal or NotRunnable). It copies rows with IDs/metadata to the archive
and deletes live rows in batches, rechecking revival before mutation. A newer
settled descendant may move with an old root. The whole tree is not moved in one
single transaction; do not interpret archival as an immutable workflow snapshot.

Default `steps:purge` removes old ticks by `created_at` and old fully settled live
trees; it does not purge archive rows. `--only-archive` deletes archive rows by
`created_at` and leaves live steps/ticks alone. `--ticks` takes precedence over
other modes and uses the registered tick predicate instead of `--days`; without
a predicate it leaves ticks untouched and exits with failure. Archive-only retention is row/date based.

Archive and purge use raw database mutations, so they do not trigger the
individual Eloquent deletion observer or remove per-step diagnostic folders.
The host must manage file retention separately. Archived workflows return
Unknown from `workflowState()` because that API examines only live rows.

## Query and triage helpers

| Public `Step` helper | Meaning |
|---|---|
| `forGroup($group)` | Exact group; null means SQL NULL |
| `forClasses($classOrClasses)` | One/many job classes |
| `forRelatable($model)` | Polymorphic owner |
| `inProgress()` | Pending, Dispatched, Running |
| `nonTerminal()` | Excludes terminal states; includes dormant NotRunnable |
| `pending()` | Pending state filter, not complete dispatcher eligibility |
| `dispatchable()` | Pending default-type rows, not complete dependency/due-time eligibility |
| `hasLiveWorkflow($owner, $classOrClasses)` | Checks in-progress owner/class work; not an atomic creation lock |
| `parentStep()`, `childSteps()`, `relatable()`, `stepTick()` | Eloquent relations |
| `isParent()`, `isChild()`, `isOrphan()`, `hasChildren()` | Structural checks |
| `isDormantResolveException()`, `parentIsRunning()` | Recovery/parent checks |
| `previousIndexIsConcluded()`, `getPrevious()` | Previous stage checks/access |
| `childStepsAreConcluded()`, `childStepsAreConcludedFromMap($map)` | Child completion checks |
| `exceptionWasAnalysed()` | Sets only the analysis flag |
| `storeExceptionVerdict($text)` | Stores the verdict; does not mark analysis complete |
| `updateSaving($attributes, $options = [])` | Fill and save |
| `updateIfNotSet($attribute, $value, $callback = null)` | Saves when the local model value is null; not a database compare-and-swap |

`tableName()`, `getTable()`, `prefix()`, `getDispatchGroup()`, state-list helpers,
parent creation and logging methods are described above/below. `StepsArchive`,
`StepsDispatcher`, and `StepsDispatcherTicks` represent archive, coordination,
and tick rows. Low-level public sweep, batch transition, dispatch-cache,
concluded-index, and nested-block helpers are implementation-oriented; their
exact signatures are in [StepDispatcher](src/Support/StepDispatcher.php).
Group lock/tick methods are in [StepsDispatcher](src/Models/StepsDispatcher.php).
Use the orchestrator rather than assembling its internal phases yourself.

## Tick observation and diagnostics

### Tick retention and slow dispatch

```php
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\Models\StepsDispatcherTicks;

StepsDispatcher::recordTickWhen(
    fn (StepsDispatcherTicks $tick): bool => $tick->progress > 0,
);

StepsDispatcher::onSlowDispatch(function (int $durationMs): void {
    logger()->warning('Slow step dispatch', ['duration_ms' => $durationMs]);
});
```

The tick predicate controls completed tick retention and `steps:purge --ticks`.
`getRecordTickWhenCallable()` returns it. `onSlowDispatch(null)` clears the runtime
callback; `getSlowDispatchCallable()` exposes it. The runtime callback takes precedence
over `dispatch.on_slow_dispatch`. A slow callback is called only for a retained
tick whose duration exceeds `dispatch.warning_threshold_ms` (default 40,000).
Tick progress and duration describe the dispatcher tick, not worker completion.
`Timing::elapsedMs($startMicrotime)` supplies the shared rounded, non-negative
elapsed-millisecond calculation.

### Cache metrics

Normal distribution attempts best-effort saturation metrics under
`dispatcher:saturation:{runtimePrefix}{group-or-global}:{UTC-minute}:...`:
`ticks_observed`, `ticks_capped`, `ticks_capped_with_leftover`,
`total_dispatched`, and `max_pending_after`.

Early-return/priority-only paths do not necessarily emit these metrics. Four
counters use cache increments without explicit TTL; only `max_pending_after`
has a 90-second TTL and its read/write maximum is non-atomic. Exceptions are
swallowed. Host code owns collection and retention. These metrics describe
selected dispatcher activity, not proof that the cap is the workload bottleneck.
`recordTickMetrics()` is public for integrations using the same schema.

### File logging

Set `STEP_DISPATCHER_LOGGING_ENABLED=true` to enable writes. The default path is
`storage_path('logs')`; override `STEP_DISPATCHER_LOGGING_PATH` when needed.
Global channels write `{path}/{channel}.log`; per-step channels write
`{path}/steps/{id}/{channel}.log`, including states, retries, throttled, and
exceptions. Channel names are sanitized; filesystem creation/writes are best effort.

`Step::log($id, $channel, $message)` and `Step::logGlobal($channel, $message)` are
available. `$step->getLogContents($channel = 'states')` returns contents or null
when absent; it can read an existing file even while writes are disabled.
`$step->clearLogs()` removes that step's folder. Individual Eloquent deletion
calls it; bulk retention does not. Separate prefixes with overlapping numeric
IDs can share a folder, so this is a diagnostic aid rather than an isolated audit
store. The `step_log` database field is separate from these files.

## Configuration reference

Keys are relative to `step-dispatcher` in [the shipped config](config/step-dispatcher.php).

| Key | Default / behavior |
|---|---|
| `queues.valid` | `default`, `priority`, `positions`, `orders`, `cronjobs`, `indicators`, `user-data-stream`; hostname forms added during creation |
| `dispatch.warning_threshold_ms` | `40000` |
| `dispatch.on_slow_dispatch` | `null`; fallback slow-tick callback |
| `dispatch.max_per_tick` | `STEP_DISPATCHER_MAX_PER_TICK`, otherwise `100`; normal candidates only; `0` disables cap |
| `flag_path` | `storage_path('step-dispatcher')`; no shipped environment mapping |
| `groups.available` | alpha through kappa; used to seed new dispatcher tables |
| `logging.enabled` | `STEP_DISPATCHER_LOGGING_ENABLED`, otherwise `false` |
| `logging.path` | `STEP_DISPATCHER_LOGGING_PATH`, otherwise `storage_path('logs')` |

## Development and verification

```bash
composer install
composer test         # Pest TIA: filtered impact selection
composer test:full    # fresh full TIA graph and full suite
```

Direct equivalents: `./run-pest-tia.sh --filtered` and
`./run-pest-tia.sh --fresh`. See [tests/TestCase.php](tests/TestCase.php) for the
SQLite in-memory setup and [run-pest-tia.sh](run-pest-tia.sh) for graph handling.
The fresh suite passed **227 tests / 583 assertions on 2026-09-28**. This is a
specific local result, not a timing SLA or cross-database CI certification.

The documentation reconciliation in [CHANGELOG.md](CHANGELOG.md) changes no
runtime behavior. Runtime baseline for this audit: v1.20.5.

## License

Proprietary; see the license declaration in [composer.json](composer.json).
