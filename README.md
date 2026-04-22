# step-dispatcher

State-machine-driven step orchestration for Laravel. Database-backed, queue-aware, horizontally scalable. Every unit of work is a row in `steps` with a tracked lifecycle — dispatched, retried, throttled, failed, or completed — and workflows compose through parent → child blocks.

```
Laravel queue  ──▶  BaseStepJob::handle()  ──▶  state transitions, retries, compute()
     ▲                         │
     │                         ▼
 steps:dispatch  ◀──  steps table (Spatie ModelStates)  ──▶  steps:recover-stale
     (cron)                                                   steps:archive
                                                              steps:purge
```

## Why

Laravel queues give you async "run this job". That's it. This package adds:

- **Durable audit trail** per job — when it started, how long it ran, how many retries, what response came back.
- **Ordered pipelines** — siblings run in `index` order, same-index siblings run in parallel, next index only dispatches when all previous-index steps concluded.
- **Parent / child composition** — a step spawns a child block; the parent waits until every descendant settles before completing.
- **Cascade-on-failure** — a failed sibling cancels the rest of the pipeline automatically.
- **Throttle-aware rescheduling** — retry *without* burning a retry count (for rate-limited API callers).
- **Horizontal scaling** — group-based sharding with `SKIP LOCKED` so N servers pull work without duplication.
- **Self-healing** — `steps:recover-stale` reclaims zombie Running steps, promotes stuck Dispatched steps, releases wedged dispatcher locks.

## Requirements

- PHP 8.2+
- Laravel 11 / 12 / 13
- MySQL 8+ / MariaDB 10.6+ / PostgreSQL 12+
- `spatie/laravel-model-states` ^2.0

## Install

```bash
composer require brunocfalcao/step-dispatcher
```

The service provider auto-registers. Publish config + migrations if you want to customise:

```bash
php artisan vendor:publish --tag=step-dispatcher-config
php artisan vendor:publish --tag=step-dispatcher-migrations
php artisan migrate
```

Set the flag directory in `.env` — this is **required**:

```env
STEP_DISPATCHER_FLAG_PATH=/var/www/my-app/storage/step-dispatcher
```

All apps sharing the same database must point to the **same** directory.

## Schedule the commands

```php
// routes/console.php (Laravel 11+) or app/Console/Kernel.php

Schedule::command('steps:dispatch')->everySecond();
Schedule::command('steps:recover-stale --recover-dispatched --release-locks')->everyMinute();
Schedule::command('steps:archive --duration=5')->hourly();
Schedule::command('steps:purge --days=30')->dailyAt('02:00');
```

## Quick start

### 1. Write a step

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

        return ['stripe_id' => $response->id]; // stored in step.response
    }
}
```

### 2. Create a step

```php
use StepDispatcher\Models\Step;

Step::create([
    'class'     => ChargeCustomerJob::class,
    'arguments' => ['customerId' => 42, 'amount' => 9900],
]);
```

That's it. The dispatcher's cron will pick it up, push it to the queue, and walk it through the state machine.

### 3. Check on it

```php
$step = Step::find($id);

$step->state;              // StepDispatcher\States\Completed (etc.)
$step->state->value();     // 'completed'
$step->response;           // ['stripe_id' => 'ch_abc123']
$step->duration;           // ms wall-clock
$step->retries;            // how many times it retried
$step->error_message;      // friendly message if failed
$step->error_stack_trace;  // full stack if failed
```

## Workflows (parent → child blocks)

```php
final class OnboardCustomerJob extends BaseStepJob
{
    protected function compute(): void
    {
        $child = $this->step->makeItAParent(); // stamps child_block_uuid

        Step::insert([
            ['class' => CreateStripeCustomerJob::class, 'block_uuid' => $child, 'index' => 1],
            ['class' => SendWelcomeEmailJob::class,     'block_uuid' => $child, 'index' => 2],
            ['class' => ProvisionDatabaseJob::class,    'block_uuid' => $child, 'index' => 3],
        ]);
        // The three children run sequentially (index 1 → 2 → 3).
        // This parent stays in Running until all three complete, then transitions to Completed.
    }
}
```

Parallel siblings (same `index`) run concurrently:

```php
Step::insert([
    ['class' => FetchStripeData::class, 'block_uuid' => $uuid, 'index' => 1],
    ['class' => FetchPlaidData::class,  'block_uuid' => $uuid, 'index' => 1], // parallel with Stripe
    ['class' => ReconcileJob::class,    'block_uuid' => $uuid, 'index' => 2], // waits for both
]);
```

## Lifecycle hooks

Override any of these on your job for fine-grained control:

| Hook | Called | Return `false` → |
|---|---|---|
| `startOrStop()` | before `compute()` | `Running → Stopped` (terminal) |
| `startOrSkip()` | before `compute()` | `Running → Skipped` (terminal, concluded) |
| `startOrFail()` | before `compute()` | throws → retry cycle |
| `startOrRetry()` | before `compute()` | `Running → Pending` with backoff |
| `doubleCheck()` | after `compute()` (max 2×) | increment `double_check`, retry |
| `confirmOrRetry()` | verification phase | `retryForConfirmation()` |
| `complete()` | before final terminal transition | do extra work (spawn children, write side records) |
| `retryException($e)` | during exception handling | retry with backoff |
| `ignoreException($e)` | during exception handling | complete anyway |
| `resolveException($e)` | during exception handling | custom resolution path |

## Throttling (rate-limited APIs)

When an API throttler says "wait 500 ms", you want to reschedule **without** incrementing the retry counter:

```php
protected function compute(): void
{
    $wait = MyApiThrottler::canDispatch();

    if ($wait > 0) {
        $this->jobBackoffMs = $wait;         // sub-second precision (1.10+)
        $this->rescheduleWithoutRetry();     // is_throttled=true, retry count untouched
        return;
    }

    // ... make the call
}
```

`dispatch_after` is `TIMESTAMP(3)` — millisecond precision is real, not rounded to the next whole second.

## Events

Listen for stall conditions (1.11+):

```php
use StepDispatcher\Events\StaleStepsDetected;

Event::listen(StaleStepsDetected::class, function (StaleStepsDetected $event) {
    // $event->severity — 'warning' | 'critical'
    // $event->reason   — 'stale_running_steps_recovered' | 'stale_dispatched_steps_promoted'
    //                 | 'stale_dispatched_steps_still_stuck' | 'stale_dispatcher_locks_released'
    // $event->count, $event->promotedCount, $event->alreadyPromotedCount, $event->releasedLocksCount
    // $event->oldestStep  — Step model
    // $event->context     — ['hostname' => ..., 'step_threshold_seconds' => ...]

    Pushover::notify($event);
});
```

The package itself never notifies anyone — your app decides the channel.

## Commands

| Command | What it does |
|---|---|
| `steps:dispatch [--group=alpha,beta]` | One tick of the dispatcher. Runs the 8-phase cascade + dispatches ready steps. |
| `steps:recover-stale` | Recovers Running zombies (worker died mid-job). |
| `steps:recover-stale --recover-dispatched --release-locks` | Also promotes stuck Dispatched steps to priority queue and force-releases wedged dispatcher locks. Fires `StaleStepsDetected`. |
| `steps:archive --duration=5` | Moves fully-resolved trees older than N days to `steps_archive`. |
| `steps:purge --days=30` | Hard-deletes `steps` / `steps_dispatcher_ticks` rows older than N days. |
| `steps:purge --ticks` | Deletes historical ticks that don't pass the `recordTickWhen` filter. |

All commands silent by default. Add `--output` for verbose stdout.

## Groups (horizontal scaling)

Ten groups (`alpha`, `beta`, …, `kappa`) ship seeded. Each has its own dispatcher lock, so N boxes can run `steps:dispatch` in parallel without duplicating work:

```bash
# Box 1
* * * * * php artisan steps:dispatch --group=alpha,beta

# Box 2
* * * * * php artisan steps:dispatch --group=gamma,delta

# …
```

Steps inherit their group from the parent or sibling in their block; root steps get round-robin assigned (`SKIP LOCKED` + microsecond-precision `last_selected_at` — no contention).

## Configuration

`config/step-dispatcher.php`:

```php
return [
    'queues' => [
        'valid' => ['default', 'priority'],  // add your own queue names here
    ],

    'dispatch' => [
        'warning_threshold_ms' => 40000,
        'on_slow_dispatch' => fn (int $ms) => Log::warning("Slow tick: {$ms}ms"),
    ],

    'flag_path' => env('STEP_DISPATCHER_FLAG_PATH'),

    'groups' => [
        'available' => ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa'],
    ],

    'logging' => [
        'enabled' => env('STEP_DISPATCHER_LOGGING_ENABLED', false),
        'path'    => env('STEP_DISPATCHER_LOGGING_PATH', storage_path('logs')),
    ],
];
```

## Diagnostic logging

Flip on for a few minutes, read the files, flip off. Each step gets a folder at `storage/logs/steps/{id}/` with channels:

```
states.log       # every state transition
retries.log      # retry cycles
throttled.log    # rescheduleWithoutRetry events
exceptions.log   # caught exceptions + handler decisions
```

Plus `storage/logs/dispatcher.log` for tick-level events. Folders are deleted automatically when the step row is archived or purged.

```env
STEP_DISPATCHER_LOGGING_ENABLED=true
```

Zero disk I/O when disabled.

## States

| State | Terminal | Notes |
|---|---|---|
| `Pending` | no | Waiting for the dispatcher |
| `Dispatched` | no | Handed to the queue, worker not yet picked up |
| `Running` | no | Worker executing `handle()` |
| `Completed` | ✓ | Success |
| `Skipped` | ✓ | Skipped on purpose — counts as concluded |
| `Cancelled` | ✓ | Cancelled by upstream failure or operator |
| `Failed` | ✓ | Exception, no retries left |
| `Stopped` | ✓ | Business-logic guard stopped it (`startOrStop`) |
| `NotRunnable` | no | Initial state for `resolve-exception` steps; promoted to Pending when the block fails |

Full transition map: see the documentation folder.

## Database shape

Three tables:

- `steps` — one row per unit of work
- `steps_dispatcher` — one row per group (lock state)
- `steps_dispatcher_ticks` — audit log of ticks (filterable via `StepsDispatcher::recordTickWhen(...)`)

Plus `steps_archive` with the same shape as `steps` for historical lookups.

Eleven indexes on `steps` cover the tick scan, parent-completion walk, block ordering, archive/purge, and admin aggregation. Purge-optimised for tables with 300k+ rows.

## Testing

Pest 3. Integration tests run against a real MySQL — the state machine's concurrency semantics don't mock cleanly.

```bash
composer test
```

## License

Proprietary. © Bruno Falcao.
