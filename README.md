# step-dispatcher

**A durable, state-machine-driven step orchestrator for Laravel.**
Every unit of work becomes a row you can query, every retry leaves an audit
trail, every multi-step workflow composes through plain SQL relationships.
The Laravel queue is still doing the work underneath — this package gives
you the things the queue alone cannot.

```
┌──────────────────┐                                  ┌────────────────────┐
│  Your code:      │      pushes a step row           │  steps table       │
│  Step::create()  │ ───────────────────────────────▶ │  (state=Pending)   │
└──────────────────┘                                  └────────┬───────────┘
                                                               │
                                  ┌────────────────────────────┘
                                  │ steps:dispatch (cron, every ~1s)
                                  ▼
                       ┌────────────────────────┐
                       │  Laravel queue worker  │
                       │  BaseStepJob::handle() │
                       └──────────┬─────────────┘
                                  │ compute() runs your business code
                                  ▼
                       ┌────────────────────────┐
                       │  State transitions:    │
                       │  Pending → Dispatched  │
                       │  → Running → Completed │
                       │  (or Failed, Skipped,  │
                       │  Retried, Throttled…)  │
                       └────────────────────────┘
```

---

## Is this for me?

You probably want step-dispatcher if you've ever needed to answer:

- *"Did this background job retry? When? How many times? Why?"*
- *"This pipeline has five stages — how do I make stage 2 wait for stage 1 to finish, and stage 3 fan out into parallel sub-jobs?"*
- *"An external API throttler told us to wait 500 ms — how do I reschedule the job without burning a retry from the limited budget?"*
- *"I want to run dispatchers on five servers without them stealing the same row from the queue table."*
- *"My worker died mid-job. How do I find the zombie and re-run it without an operator noticing at 3 a.m.?"*
- *"I need an audit row in MySQL per job — Horizon's dashboard isn't enough."*

Laravel's queue answers *"run this thing asynchronously"*. It does not answer any of the above on its own. step-dispatcher does — by treating every job as a **row in a table with a tracked lifecycle**, then layering ordered pipelines, parent/child composition, retry-aware throttling, and self-healing recovery on top.

If your background work is a single `dispatch(new SendEmailJob)` you do not need this package. If your background work is *"start with A, then B+C in parallel, then D, and if anything fails cascade-cancel the rest, retry exponentially on the network layer but not on validation errors, and let me see the full timeline a week later"* — you need this package.

---

## The mental model in 60 seconds

Forget "jobs" for a moment. Think **rows**.

You insert a row into the `steps` table. The row carries:

- **`class`** — which PHP class will eventually run (a subclass of `BaseStepJob`).
- **`arguments`** — a JSON blob of inputs (numeric IDs, scalars; no closures).
- **`state`** — where this row is in its lifecycle. Starts at `Pending`.
- **`block_uuid`** / **`index`** / **`child_block_uuid`** — how this row connects to siblings and children in a workflow.
- **`priority`**, **`group`**, **`queue`** — knobs that control *when* and *where* this row gets picked up.

A scheduled command (`steps:dispatch`) ticks every second. On each tick it:

1. Selects Pending rows whose dependencies are satisfied (no unfinished
   earlier-index sibling, no unfinished parent gate).
2. Pushes each one onto a Laravel queue using the row's `queue` column.
3. Updates the row's state to `Dispatched`.

A Horizon worker eventually picks the job off the queue, calls
`BaseStepJob::handle()` → `compute()` (your code), and the row transitions
to `Running` → `Completed` (or `Failed`, `Skipped`, `Cancelled`, `Stopped`).

If your `compute()` decides to spawn child work, you assign a
`child_block_uuid` to the parent row and insert children carrying that uuid
as their `block_uuid`. The parent stays in `Running` until every descendant
settles, then transitions to `Completed`.

That's the whole product. Everything below is detail.

---

## Why each feature exists

| Feature | The problem it solves |
|---|---|
| **Durable row per job** | Laravel queues only remember failures (`failed_jobs`). step-dispatcher remembers every job, retried or not — when it started, when it finished, what the response was, what arguments triggered it. Auditability for post-mortems. |
| **State machine (Spatie ModelStates)** | Hand-rolled `if ($status === 'pending')` checks miss edge cases. The state machine enforces legal transitions only — you cannot accidentally re-run a Completed step or skip from Pending straight to Failed. |
| **Parent → child composition** | Workflows are not single jobs; they are trees. A row can spawn a child block, and the parent waits until every descendant settles. Lets you express "do A, then do these 3 things in parallel, then do D" as data, not as Laravel chaining magic that breaks on retry. |
| **Sibling ordering by `index`** | Same `block_uuid`, ascending `index` → run sequentially. Same `block_uuid`, same `index` → run in parallel. Two integers express the whole shape. |
| **Cascade-on-failure** | When a sibling fails, the rest of the pipeline auto-cancels. You don't have to remember to write that logic into every workflow. |
| **Throttle-aware retry** | An API throttler saying "wait 500 ms" is not the same as a real failure. `rescheduleWithoutRetry()` puts the row back to Pending with a sub-second backoff, without incrementing the retry counter or burning a `failed_jobs` slot. |
| **Horizontal scaling via groups** | Ten dispatcher groups (`alpha`..`kappa`) ship seeded. Each group has its own row-level lock. N servers can run dispatchers in parallel — group affinity guarantees no two boxes pick up the same row. |
| **Self-healing recovery** | `steps:recover-stale` finds Running rows whose worker died (no heartbeat past a threshold), reverts them to Pending, and promotes long-Dispatched rows to a priority queue so they don't stall the pipeline. Fires a `StaleStepsDetected` event so your app decides how to alert someone (Pushover, Slack, etc.). |
| **Queue resolver hook** *(v1.13+)* | Lets the host app inspect each step at dispatch time and decide *which physical queue to push it onto*. Used by Kraite to pick a worker IP that isn't currently rate-limited by the target exchange. |
| **Table-prefix isolation** *(v1.12+)* | Run N independent step-dispatcher ecosystems against the same database. Each prefix owns its own `{prefix}steps`, `{prefix}steps_dispatcher`, `{prefix}steps_dispatcher_ticks`, `{prefix}steps_archive`. Lets you run "trading" steps and "marketing" steps under one Laravel app without them interleaving. |

---

## Requirements

- PHP 8.2+
- Laravel 11 / 12 / 13
- MySQL 8+ / MariaDB 10.6+ / PostgreSQL 12+ (the recursive child-block CTE
  walks both engines; SQLite is not supported because of the millisecond
  TIMESTAMP(3) requirement)
- `spatie/laravel-model-states` ^2.0
- A Redis instance (used by saturation telemetry; falls back gracefully if
  unavailable, but you'll lose tick-cap visibility)

---

## Install

```bash
composer require brunocfalcao/step-dispatcher
```

The service provider auto-registers. Publish migrations + config if you want to inspect or customise:

```bash
php artisan vendor:publish --tag=step-dispatcher-config
php artisan vendor:publish --tag=step-dispatcher-migrations
php artisan migrate
```

Set the flag-file directory — this is **required**, the package refuses to boot without it:

```env
STEP_DISPATCHER_FLAG_PATH=/var/www/my-app/storage/step-dispatcher
```

The directory holds one tiny file per dispatcher prefix that signals "this prefix is currently active" to the dispatcher cron. All Laravel apps sharing the same database must point to the **same** directory, otherwise a worker app could miss a flag set by a manager app.

---

## Schedule the dispatcher commands

The dispatcher ticks via Laravel's scheduler. Pick **one** of the two wiring shapes below — don't mix them, the per-group entries replace the global one.

### Recommended: per-group parallel dispatchers

One scheduler entry per group, each forked into its own subprocess via `runInBackground()`. Each group ticks every second independently — total cadence is unaffected by how many groups you run. This is how the Kraite project ships in production.

```php
// routes/console.php (Laravel 11+) or app/Console/Kernel.php

$groups = ['alpha', 'beta', 'gamma', 'delta', 'epsilon',
           'zeta', 'eta', 'theta', 'iota', 'kappa'];

foreach ($groups as $group) {
    Schedule::command("steps:dispatch --group={$group}")
        ->everySecond()
        ->runInBackground();   // CRITICAL — see callout below
}

Schedule::command('steps:recover-stale --recover-dispatched --release-locks --watchdog-progress')
    ->everyMinute();

Schedule::command('steps:archive --duration=5')->hourly();
Schedule::command('steps:purge --days=30')->dailyAt('02:00');
```

> **Why `runInBackground()` matters.** Without it the scheduler runs the 10 dispatch commands serially in a single PHP process. Each tick takes 0.5–1 s of bootstrap, so per-group cadence degrades from "every second" to "every 5–10 seconds" and the whole point of having groups is lost. With `runInBackground()` each scheduled entry fires its own subprocess and the 10 dispatchers actually run in parallel.

### Alternative: single global dispatcher

If you only have a handful of steps and don't need parallel dispatching, you can run one `steps:dispatch` (no `--group`) that loops all groups serially in a single process. Per-group cadence ≈ `total groups × per-tick duration`. Fine for small workloads, doesn't scale.

```php
Schedule::command('steps:dispatch')->everySecond();
```

### Long-running daemon (Kraite-style)

If your app handles >100 steps/minute you may prefer a persistent daemon instead of repeated scheduler forks. Kraite ships `kraite:dispatch-daemon` which is one supervisord-managed PHP process running `while (true) { dispatch all groups; usleep(1000ms); }`. Replaces ~20 scheduler forks per minute with one resident process. The package itself doesn't ship the daemon — your app writes it. Reference implementation lives in `kraitebot/core` at `src/Commands/DispatchDaemonCommand.php`.

---

## Hello world

This is the entire happy path. Three steps.

### 1. Write a job class

```php
use StepDispatcher\Abstracts\BaseStepJob;

final class ChargeCustomerJob extends BaseStepJob
{
    public int $retries = 3;
    public int $timeout = 60;

    protected function compute(): mixed
    {
        $customer = Customer::find($this->step->arguments['customerId']);
        $response = StripeApi::charge($customer->id, $this->step->arguments['amount']);

        return ['stripe_id' => $response->id];   // anything you return lands in step.response
    }
}
```

Three things to notice:

- **You extend `BaseStepJob`, not Laravel's standard `Job`.** That's how the package wires retry / state / observer machinery.
- **`compute()` is what you implement.** Not `handle()` — `handle()` is the orchestration entry point that the package owns. `compute()` is where your business code goes.
- **`$this->step` is the row.** Always there. `$this->step->arguments` is your input. Whatever `compute()` returns becomes `$this->step->response`.

### 2. Create a step

```php
use StepDispatcher\Models\Step;

Step::create([
    'class'     => ChargeCustomerJob::class,
    'arguments' => ['customerId' => 42, 'amount' => 9900],
]);
```

That's it. The dispatcher's cron will pick it up on the next tick (usually
< 1 s away), push it to the Laravel queue, your worker runs `compute()`,
the row transitions to `Completed`.

### 3. Look at it later

```php
$step = Step::find($id);

$step->state;              // StepDispatcher\States\Completed
$step->state->value();     // 'completed'
$step->response;           // ['stripe_id' => 'ch_abc123']
$step->duration;           // milliseconds, wall-clock
$step->retries;            // how many times it retried before settling
$step->error_message;      // human-friendly explanation if Failed
$step->error_stack_trace;  // full PHP stack if Failed
```

No `failed_jobs` table to grep, no Horizon dashboard to scroll, no log file to tail. The row carries everything.

---

## Workflows — parent / child composition

A step can decide, while running, to spawn a child block. The parent stays
`Running` until every descendant settles, then transitions to `Completed`.

```php
final class OnboardCustomerJob extends BaseStepJob
{
    protected function compute(): void
    {
        $child = $this->step->makeItAParent(); // returns a fresh child_block_uuid

        Step::insert([
            ['class' => CreateStripeCustomerJob::class, 'block_uuid' => $child, 'index' => 1],
            ['class' => SendWelcomeEmailJob::class,     'block_uuid' => $child, 'index' => 2],
            ['class' => ProvisionDatabaseJob::class,    'block_uuid' => $child, 'index' => 3],
        ]);

        // OnboardCustomerJob stays Running until ALL three children Complete.
        // If ANY child Fails, the rest of the children Cancel and the parent transitions to Failed.
    }
}
```

`index` is the sequencer. Same `block_uuid`, ascending `index` → run sequentially. Same `block_uuid`, same `index` → run in parallel.

Parallel siblings:

```php
Step::insert([
    // index 1 — both run concurrently
    ['class' => FetchStripeData::class, 'block_uuid' => $uuid, 'index' => 1],
    ['class' => FetchPlaidData::class,  'block_uuid' => $uuid, 'index' => 1],

    // index 2 — waits until BOTH index-1 steps Complete, then runs
    ['class' => ReconcileJob::class,    'block_uuid' => $uuid, 'index' => 2],
]);
```

The dispatcher walks block dependencies on every tick — you do not orchestrate
the gate yourself. Children inherit the parent's `group`, so a workflow stays
on one dispatcher group end-to-end (no cross-group races).

---

## Lifecycle hooks

`BaseStepJob` exposes hooks for fine-grained control over each step's
journey. Override only the hooks you need; the rest fall through to no-op
defaults.

| Hook | Called | Returning `false` causes |
|---|---|---|
| `startOrStop()` | before `compute()` | `Running → Stopped` (terminal, business-logic guard) |
| `startOrSkip()` | before `compute()` | `Running → Skipped` (terminal, counts as concluded) |
| `startOrFail()` | before `compute()` | throws → enters retry cycle |
| `startOrRetry()` | before `compute()` | `Running → Pending` with backoff (without burning a retry) |
| `doubleCheck()` | after `compute()`, up to 2× | increments `double_check`, re-runs |
| `confirmOrRetry()` | verification phase | calls `retryForConfirmation()` |
| `complete()` | before final terminal transition | space for side effects — spawn children, write audit records, fire app-level events |
| `retryException($e)` | during exception handling | retry with backoff (uses `$retries`) |
| `ignoreException($e)` | during exception handling | mark Completed regardless of the exception |
| `resolveException($e)` | during exception handling | custom resolution path (e.g. spawn a fallback step) |

A typical "check the world before doing the work" job:

```php
protected function startOrSkip(): bool
{
    // Returns false to skip — the customer was deleted before we picked up the row.
    return Customer::query()->whereKey($this->step->arguments['customerId'])->exists();
}

protected function startOrStop(): bool
{
    // Hard stop — business rule says we don't charge cancelled accounts.
    return ! Customer::find($this->step->arguments['customerId'])->is_cancelled;
}

protected function compute(): mixed
{
    // Reaches here only if startOrSkip + startOrStop both returned true.
    // ...
}
```

---

## Throttling — talking to rate-limited APIs

When you talk to an external API that rate-limits, you want to reschedule
the step **without** spending a retry from the budget. That's
`rescheduleWithoutRetry()` plus the `$jobBackoffMs` knob:

```php
protected function compute(): void
{
    $waitMs = MyApiThrottler::canDispatch();

    if ($waitMs > 0) {
        $this->jobBackoffMs = $waitMs;     // sub-second precision (since v1.10)
        $this->rescheduleWithoutRetry();   // sets is_throttled=true, retry count stays put
        return;
    }

    // ... actually make the API call
}
```

The `dispatch_after` column is `TIMESTAMP(3)` — millisecond precision is real, not rounded up to the next whole second. A throttler returning `350 ms` will hold the step for exactly 350 ms.

The dispatcher's two-pass step selection (since v1.11.9) prioritises non-throttled steps within each tick window, so a flock of throttled steps never starves a fresh one.

---

## Events — getting notified when things go wrong

The package publishes a single event class. Listening for it lets your app
decide how to surface stall conditions (Pushover, Slack, PagerDuty, email,
whatever).

```php
use StepDispatcher\Events\StaleStepsDetected;

Event::listen(StaleStepsDetected::class, function (StaleStepsDetected $event) {
    /*
     * $event->severity        — 'warning' | 'critical'
     * $event->reason          — one of:
     *                            'stale_running_steps_recovered'
     *                            'stale_dispatched_steps_promoted'
     *                            'stale_dispatched_steps_still_stuck'  (critical)
     *                            'stale_dispatcher_locks_released'
     * $event->count           — how many steps in this event
     * $event->promotedCount   — newly promoted to priority
     * $event->alreadyPromotedCount — already on priority but still stuck
     * $event->releasedLocksCount   — dispatcher locks force-released
     * $event->oldestStep      — the Step model (handy for "stuck since…" alerts)
     * $event->context         — array — 'hostname', 'step_threshold_seconds', etc.
     */

    Pushover::notify(/* build alert from $event */);
});
```

The package itself never notifies anyone. It detects, it emits, you decide
the channel.

---

## Commands

| Command | What it does |
|---|---|
| `steps:dispatch` | One tick of the dispatcher. Runs the 8-phase cascade (skip → cascade-cancel → promote-exception → parent-fail → parent-stop → cascade-cancel children → parent-complete → distribute) and dispatches ready steps. |
| `steps:dispatch --group=alpha,beta` | Same, but only for the named groups (comma-separated). |
| `steps:recover-stale` | Recovers Running zombies (worker died without transitioning). |
| `steps:recover-stale --recover-dispatched --release-locks --watchdog-progress` | Also promotes stuck Dispatched steps to the priority queue and force-releases wedged dispatcher locks. Fires `StaleStepsDetected`. |
| `steps:archive --duration=5` | Moves fully-resolved trees older than N days to `steps_archive` (cold storage, same shape). |
| `steps:purge --days=30` | Hard-deletes `steps` / `steps_dispatcher_ticks` rows older than N days (tree-aware on `steps` — children are never orphaned). |
| `steps:purge --only-archive --days=30` | Hard-deletes ONLY `steps_archive` rows older than N days. Pair with `steps:archive` for a two-stage retention pipeline (hot → cold → gone). |
| `steps:purge --ticks` | Deletes historical tick rows that don't pass the `recordTickWhen` filter. |
| `steps:install --prefix=<name>` | Creates a new prefixed table set (see "Table-prefix isolation" below). |

All commands are silent by default — they fit cleanly inside cron. Add `--output` to any of them for verbose stdout (useful in dev / when running manually).

---

## Horizontal scaling — groups

Ten dispatcher groups (`alpha`, `beta`, …, `kappa`) ship seeded out of the box. Each group has its own row-level lock (acquired via `SKIP LOCKED`), so N boxes can run dispatchers in parallel without ever picking up the same row twice.

Two common deployment shapes:

**One box per dispatcher group set:**

```bash
# box-1.example.com cron
* * * * * php artisan steps:dispatch --group=alpha,beta

# box-2.example.com cron
* * * * * php artisan steps:dispatch --group=gamma,delta

# box-3.example.com cron
* * * * * php artisan steps:dispatch --group=epsilon,zeta
```

**All boxes dispatch all groups (race-free thanks to SKIP LOCKED):**

```bash
# every box, same line
* * * * * php artisan steps:dispatch
```

Steps inherit their group from the parent or sibling in their block. Root steps (no parent, no siblings) get round-robin assigned via `SKIP LOCKED` + microsecond `last_selected_at` — distribution is even, no contention, no manual sharding.

---

## Table-prefix isolation — running N step-dispatcher ecosystems side by side

*(Since v1.12.)*

You can run completely isolated step-dispatcher ecosystems against the same MySQL database. Each ecosystem owns its own four-table set:

- `{prefix}steps`
- `{prefix}steps_dispatcher`
- `{prefix}steps_dispatcher_ticks`
- `{prefix}steps_archive`

The prefix is a runtime concern — the same dispatcher code runs against any prefix. A scoped singleton (`RuntimeContext`) holds the active prefix on a stack; every model query resolves its table via `tableName()` helpers that read that stack.

Three ways to set the active prefix (precedence: explicit > closure > ambient > default):

```php
// 1. Ambient via CLI — the package injects --prefix= onto every command
php artisan steps:dispatch --prefix=trading --group=alpha
php artisan steps:archive --prefix=trading --duration=1
php artisan steps:purge   --prefix=trading --only-archive --days=5

// 2. Closure-scoped (host code) — push/pop balanced even on throw
use StepDispatcher\Support\Steps;

Steps::usingPrefix('trading', function () use ($position) {
    Step::create([
        'class'     => MyJob::class,
        'arguments' => ['positionId' => $position->id],
    ]);
});

// 3. Single-call explicit — bind one builder to a specific prefix
Step::prefix('calc')->create([...]);
```

The default (empty) prefix `''` resolves to the original `steps_*` tables, so existing host apps keep working with zero changes.

### Installing a new prefix

```bash
php artisan steps:install --prefix=trading
```

Creates the four prefixed tables programmatically with prefix-interpolated index names so they never collide. Per-table idempotent: existing tables are skipped, missing ones created (re-run heals a partial drop). The `alpha..kappa` group seed only fires when the dispatcher table is genuinely created, never on a re-run.

Empty prefix is rejected — that's what the stock migrations install. You cannot accidentally re-install the default set.

### What gets isolated by a prefix

- **Tables** — physically separate.
- **Dispatcher cap** — each prefix has its own `steps_dispatcher` rows, so the per-tick `max_per_tick` budget applies independently.
- **Saturation cache keys** — `dispatcher:saturation:*` keys are prefix-scoped (no cross-prefix stomping).
- **Active flag file** — `{flag_dir}/{prefix}active.flag` (one prefix going idle does not deactivate the others).
- **Tick-id cache keys** — `current_tick_id:{prefix}{group}` (two prefixes sharing a group name don't collide).
- **Recursive child-block CTE** — `Step::tableName()` is interpolated into raw SQL so the descendant walk hits the right table set.

### How the prefix propagates from dispatcher → worker → child

`BaseStepJob` carries a `public string $stepPrefix` that's stamped onto the queue payload at dispatch time. When the worker pops the job:

1. `__unserialize()` pushes the prefix onto `RuntimeContext` **before** Laravel's `SerializesModels` trait restores the `$step` model. Mandatory ordering — Laravel's restore calls `Step::find($id)` deserialize-time, which would query the wrong table without the gate pre-set.
2. `handle()` re-pushes the prefix for the execution body, balanced by a `pop()` in `finally`.
3. Any `Step::create()` issued inside `compute()` inherits the ambient prefix — child blocks land in the same prefixed set as their parent, all the way down a multi-hop workflow chain.
4. `failed()` (called by Laravel directly on terminal failure, bypassing `handle()`) has its own push/pop pair so the Failed transition writes to the correct prefixed table.

### Cron entries for a prefixed dispatcher

Just append `--prefix=<name>` to every dispatcher / archive / purge / recover-stale entry:

```php
foreach (['alpha', 'beta', /* ... */] as $group) {
    Schedule::command("steps:dispatch --prefix=trading --group={$group}")
        ->everySecond()
        ->runInBackground();
}

Schedule::command('steps:recover-stale --prefix=trading --recover-dispatched --release-locks --watchdog-progress')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('steps:archive --prefix=trading --duration=1')->dailyAt('04:05');
Schedule::command('steps:purge   --prefix=trading --only-archive --days=5')->dailyAt('04:35');
```

---

## Dispatch-time queue routing — the resolver hook

*(Since v1.13.)*

The package lets the host app override the queue each step lands on, at dispatch time, right before it gets pushed onto Redis. You wire a callable via `StepDispatcher::setQueueResolver()`:

```php
use StepDispatcher\Models\Step;
use StepDispatcher\Support\StepDispatcher;

// In your application's ServiceProvider::boot()
StepDispatcher::setQueueResolver(static function (Step $step): ?string {
    // Inspect the step. Decide what physical queue should serve it.
    // Return:
    //   - a string  → overrides step.queue, pushed to that queue
    //   - null      → "no opinion", step.queue stays as-is
    //   - throw NoCleanWorkerException → step transitions to Failed
    return MyRouter::resolveFor($step);
});
```

This hook fires once per dispatch (not once per retry of an already-dispatched step). Sync steps (`queue=sync`) bypass the resolver entirely — synchronous execution doesn't go through Redis so routing has no meaning.

The Kraite project uses this hook to implement per-account worker-IP rotation: when an exchange rate-limits a specific worker's IP, the resolver removes that worker from the candidate pool, picks a surviving one, and composes the physical queue name (`{hostname}-{logical}`, e.g. `eos-positions`) accordingly. When the whole pool is exhausted by permanent bans, the resolver throws `NoCleanWorkerException` and step-dispatcher transitions the step to Failed automatically.

If your app doesn't register a resolver, step-dispatcher pushes onto `step.queue` verbatim — original behaviour, no breakage.

---

## Configuration

`config/step-dispatcher.php`:

```php
return [
    'queues' => [
        // Names allowed in step.queue. Anything not on this list gets demoted
        // to 'default' by the StepObserver on save. Your app extends this if
        // it uses additional queue names (the resolver hook above is one
        // common reason).
        'valid' => ['default', 'priority'],
    ],

    'dispatch' => [
        // If a single tick takes longer than this, fire the closure below.
        // Useful for slow-tick alerts.
        'warning_threshold_ms' => 40000,
        'on_slow_dispatch' => fn (int $ms) => Log::warning("Slow dispatcher tick: {$ms}ms"),
    ],

    'flag_path' => env('STEP_DISPATCHER_FLAG_PATH'),

    'groups' => [
        // Override the default 10 groups here if you want fewer / different names.
        'available' => ['alpha', 'beta', 'gamma', 'delta', 'epsilon',
                        'zeta', 'eta', 'theta', 'iota', 'kappa'],
    ],

    'logging' => [
        // Toggle the per-step diagnostic logging (see below).
        'enabled' => env('STEP_DISPATCHER_LOGGING_ENABLED', false),
        'path'    => env('STEP_DISPATCHER_LOGGING_PATH', storage_path('logs')),
    ],
];
```

---

## Saturation telemetry

Each dispatcher tick increments four Redis counters keyed by `(group, UTC-minute bucket)`:

- `ticks_observed` — total ticks in this bucket
- `ticks_capped` — ticks where `dispatchable_count == max_per_tick`
- `ticks_capped_with_leftover` — capped ticks where Pending was *still* > 0 after promotion
- `total_dispatched` — sum of rows promoted to Dispatched

Plus a `max_pending_after` running gauge.

The writes are wrapped in try/catch — telemetry must **never** break dispatch. Counters expire 90 s after their bucket closes, so even with no consumer they self-clean.

**Saturation %** per minute-bucket = `ticks_capped_with_leftover / ticks_observed × 100`.

- `100%` sustained across all groups → the dispatcher's per-tick cap is the bottleneck. Adding more groups (and another box to run them) helps.
- `<100%` → cap is not the bottleneck. Look downstream (worker count, API rate limits, observer chain, slow `compute()` body).

A host-app cron is expected to flush each closed minute's keys into a persistent table for dashboard surface. The Kraite project ships `kraite:cron-flush-dispatcher-saturation` + `steps_dispatcher_saturation` migration as a reference implementation.

---

## Diagnostic logging

Flip the env flag on for a few minutes, read the files, flip it off. Each step gets a folder at `storage/logs/steps/{id}/` with channels:

```
states.log       # every state transition
retries.log      # retry cycles
throttled.log    # rescheduleWithoutRetry events
exceptions.log   # caught exceptions + handler decisions
```

Plus `storage/logs/dispatcher.log` for tick-level events. Folders are auto-deleted when the step row is archived or purged.

```env
STEP_DISPATCHER_LOGGING_ENABLED=true
```

Zero disk I/O when disabled (the channel writers short-circuit at the env check).

---

## States — the full vocabulary

| State | Terminal? | Meaning |
|---|---|---|
| `Pending` | no | Waiting for the dispatcher to pick it up |
| `Dispatched` | no | Pushed to the Laravel queue, worker not yet picked up |
| `Running` | no | Worker is executing `handle()` |
| `Completed` | ✓ | Success |
| `Skipped` | ✓ | Skipped on purpose — counts as concluded for parent-completion logic |
| `Cancelled` | ✓ | Cancelled by upstream failure (cascade) or operator action |
| `Failed` | ✓ | Exception, no retries left |
| `Stopped` | ✓ | Business-logic guard returned false from `startOrStop()` |
| `NotRunnable` | no | Initial state for resolve-exception fallback steps; promoted to Pending only when the parent block fails |

The full transition map (which states can legally move to which) lives in `src/States/` — each state class declares its `transitionableStates()`. The state machine refuses any move that's not on the list, so a half-finished operator script cannot put a Completed step back to Running.

---

## Database shape

Three tables, plus a cold-storage twin:

| Table | Purpose |
|---|---|
| `steps` | One row per unit of work. The thing your code interacts with. |
| `steps_dispatcher` | One row per group. Tracks the dispatcher lock state per group. |
| `steps_dispatcher_ticks` | Audit log of every tick — filterable via `StepsDispatcher::recordTickWhen(...)` so you only record interesting ticks (e.g., only when something was actually dispatched). |
| `steps_archive` | Same shape as `steps`. Receives fully-resolved trees from `steps:archive`. |

Eleven indexes on `steps` cover the tick scan, parent-completion walk, block ordering, archive/purge, and admin aggregation. The package is purge-optimised for tables with 300 k+ rows.

---

## Testing

Pest 3. Integration tests run against a real MySQL — the state machine's concurrency semantics (SKIP LOCKED, deferred constraint checks, recursive CTE on parent walk) don't mock cleanly.

```bash
composer test
```

178 tests, ~2 s on a modern dev box. CI matrix covers PHP 8.2 / 8.3 / 8.4 and Laravel 11 / 12 / 13.

---

## License

Proprietary. © Bruno Falcao.
