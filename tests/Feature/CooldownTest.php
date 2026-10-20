<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use StepDispatcher\Events\StaleStepsDetected;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsCooldown;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Support\Cooldown;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Support\Steps;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

/*
|--------------------------------------------------------------------------
| Cooldown and warmup
|--------------------------------------------------------------------------
|
| A cooldown stops new work from starting while started work ends:
|  - steps can still be created; they wait as Pending;
|  - a top-level block (steps whose block is nobody's child block) that
|    has not started does not start;
|  - a top-level block that has started is atomic: its remaining steps
|    and every step below it still run to the end;
|  - the dispatcher's bookkeeping (cascades, parent completion) goes on.
| A cooldown is global or limited to one scope (one table set). A global
| warmup ends every cooldown; a scope warmup ends only that scope's own.
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    Queue::fake();
    Cooldown::flushState();
});

/** @param  array<string, mixed>  $attributes */
function cooldownStep(array $attributes = []): Step
{
    return Step::create(array_merge([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'alpha',
        'state' => Pending::class,
    ], $attributes));
}

function cooldownStateOf(Step $step): string
{
    return (string) DB::table($step->getTable())->where('id', $step->id)->value('state');
}

/** Run a few ticks so early-returning bookkeeping phases cannot hide a dispatch. */
function cooldownTicks(?string $group = 'alpha', int $ticks = 3): void
{
    for ($i = 0; $i < $ticks; $i++) {
        StepDispatcher::dispatch($group);
    }
}

function installTradingScope(): void
{
    test()->artisan('steps:install', ['--prefix' => 'trading'])->assertSuccessful();
}

/*
| Baseline
*/

it('dispatches as before when no cooldown is set', function (): void {
    $step = cooldownStep();

    cooldownTicks();

    expect(cooldownStateOf($step))->toBe(Dispatched::class)
        ->and(StepDispatcher::isCooldownActive())->toBeFalse()
        ->and(StepDispatcher::isCooldownActive(''))->toBeFalse()
        ->and(StepDispatcher::isInCoolDown())->toBeFalse()
        ->and(StepDispatcher::isCooledDown())->toBeFalse()
        ->and(DB::table('steps_cooldowns')->count())->toBe(0);
});

/*
| Holding new work
*/

it('holds a top-level block that has not started', function (): void {
    $step = cooldownStep();

    StepDispatcher::cooldown();
    cooldownTicks();

    expect(cooldownStateOf($step))->toBe(Pending::class);
});

it('still accepts new steps during a cooldown and keeps them Pending', function (): void {
    StepDispatcher::cooldown();
    StepDispatcher::deactivate();

    $step = cooldownStep();

    expect(Step::count())->toBe(1)
        ->and(cooldownStateOf($step))->toBe(Pending::class)
        ->and(StepDispatcher::isActive())->toBeTrue();

    cooldownTicks();

    expect(cooldownStateOf($step))->toBe(Pending::class);
});

it('holds every step of a multi-step top-level block that has not started', function (): void {
    $block = (string) Str::uuid();
    $first = cooldownStep(['block_uuid' => $block, 'index' => 1]);
    $parallel = cooldownStep(['block_uuid' => $block, 'index' => 1]);
    $second = cooldownStep(['block_uuid' => $block, 'index' => 2]);

    StepDispatcher::cooldown();
    cooldownTicks();

    expect([cooldownStateOf($first), cooldownStateOf($parallel), cooldownStateOf($second)])
        ->toBe([Pending::class, Pending::class, Pending::class]);
});

it('does not count a dormant resolve-exception as a started block', function (): void {
    $block = (string) Str::uuid();
    cooldownStep(['block_uuid' => $block, 'index' => 2, 'type' => 'resolve-exception']);
    $first = cooldownStep(['block_uuid' => $block, 'index' => 1]);

    StepDispatcher::cooldown();
    cooldownTicks();

    expect(cooldownStateOf($first))->toBe(Pending::class)
        ->and(Cooldown::blocksOfStartedTrees([$block]))->toBe([]);
});

it('holds the child blocks of a top-level block that has not started', function (): void {
    $childBlock = (string) Str::uuid();
    $parent = cooldownStep(['child_block_uuid' => $childBlock]);
    cooldownStep(['block_uuid' => $childBlock]);

    StepDispatcher::cooldown();

    expect(Cooldown::blocksOfStartedTrees([$childBlock, $parent->block_uuid]))->toBe([]);
});

/*
| Letting started work finish
*/

it('runs the next step of a top-level block that has already started', function (): void {
    $block = (string) Str::uuid();
    cooldownStep(['block_uuid' => $block, 'index' => 1, 'state' => Completed::class]);
    $next = cooldownStep(['block_uuid' => $block, 'index' => 2]);
    $held = cooldownStep();

    StepDispatcher::cooldown();
    cooldownTicks();

    expect(cooldownStateOf($next))->toBe(Dispatched::class)
        ->and(cooldownStateOf($held))->toBe(Pending::class);
});

it('runs every remaining index of a started block, one after the other', function (): void {
    $block = (string) Str::uuid();
    cooldownStep(['block_uuid' => $block, 'index' => 1, 'state' => Completed::class]);
    $second = cooldownStep(['block_uuid' => $block, 'index' => 2]);
    $third = cooldownStep(['block_uuid' => $block, 'index' => 3]);

    StepDispatcher::cooldown();
    cooldownTicks();

    expect(cooldownStateOf($second))->toBe(Dispatched::class)
        ->and(cooldownStateOf($third))->toBe(Pending::class);

    DB::table('steps')->where('id', $second->id)->update(['state' => Completed::class]);
    cooldownTicks();

    expect(cooldownStateOf($third))->toBe(Dispatched::class);
});

it('runs the children of a running parent in a started block', function (): void {
    $childBlock = (string) Str::uuid();
    cooldownStep(['child_block_uuid' => $childBlock, 'state' => Running::class]);
    $child = cooldownStep(['block_uuid' => $childBlock]);

    StepDispatcher::cooldown();
    cooldownTicks();

    expect(cooldownStateOf($child))->toBe(Dispatched::class);
});

it('runs grandchildren two levels below a started top-level block', function (): void {
    $childBlock = (string) Str::uuid();
    $grandchildBlock = (string) Str::uuid();
    $root = cooldownStep(['child_block_uuid' => $childBlock, 'state' => Running::class]);
    cooldownStep(['block_uuid' => $childBlock, 'child_block_uuid' => $grandchildBlock, 'state' => Running::class]);
    $grandchild = cooldownStep(['block_uuid' => $grandchildBlock]);

    StepDispatcher::cooldown();
    cooldownTicks();

    expect(cooldownStateOf($grandchild))->toBe(Dispatched::class)
        ->and(Cooldown::blocksOfStartedTrees([$grandchildBlock]))->toBe([$grandchildBlock])
        ->and(Cooldown::blocksOfStartedTrees([$root->block_uuid]))->toBe([$root->block_uuid]);
});

it('keeps a block started when its step came back to Pending', function (array $marker): void {
    $block = (string) Str::uuid();
    $step = cooldownStep(['block_uuid' => $block] + $marker);

    StepDispatcher::cooldown();
    cooldownTicks();

    expect(cooldownStateOf($step))->toBe(Dispatched::class);
})->with([
    'handed out once before' => [['tick_id' => 42]],
    'retried' => [['retries' => 1]],
    'throttled before' => [['was_throttled' => true]],
]);

it('runs a promoted resolve-exception of a started block', function (): void {
    $block = (string) Str::uuid();
    cooldownStep(['block_uuid' => $block, 'index' => 1, 'state' => Failed::class]);
    // A resolve-exception sits at index 1 (the observer's default) so it may run once promoted.
    $resolver = cooldownStep(['block_uuid' => $block, 'index' => 1, 'type' => 'resolve-exception']);

    expect(cooldownStateOf($resolver))->toBe(NotRunnable::class);

    StepDispatcher::cooldown();
    cooldownTicks(ticks: 5);

    expect(cooldownStateOf($resolver))->toBe(Dispatched::class);
});

it('still completes a parent whose children finished during the cooldown', function (): void {
    $childBlock = (string) Str::uuid();
    $parent = cooldownStep(['child_block_uuid' => $childBlock, 'state' => Running::class]);
    cooldownStep(['block_uuid' => $childBlock, 'state' => Completed::class]);

    StepDispatcher::cooldown();
    cooldownTicks();

    expect(cooldownStateOf($parent))->toBe(Completed::class);
});

it('does not let held blocks starve started work under the per-tick cap', function (): void {
    config()->set('step-dispatcher.dispatch.max_per_tick', 1);

    // Older ids first: held work would fill a one-row tick if it were not filtered out in SQL.
    $heldA = cooldownStep();
    $heldB = cooldownStep();
    $block = (string) Str::uuid();
    cooldownStep(['block_uuid' => $block, 'index' => 1, 'state' => Completed::class]);
    $next = cooldownStep(['block_uuid' => $block, 'index' => 2]);

    StepDispatcher::cooldown();
    StepDispatcher::dispatch('alpha');

    expect(cooldownStateOf($next))->toBe(Dispatched::class)
        ->and(cooldownStateOf($heldA))->toBe(Pending::class)
        ->and(cooldownStateOf($heldB))->toBe(Pending::class);
});

it('applies the same rule to priority steps', function (): void {
    $heldPriority = cooldownStep(['priority' => 'high', 'queue' => 'priority']);
    $block = (string) Str::uuid();
    cooldownStep(['block_uuid' => $block, 'index' => 1, 'state' => Completed::class, 'priority' => 'high']);
    $nextPriority = cooldownStep(['block_uuid' => $block, 'index' => 2, 'priority' => 'high', 'queue' => 'priority']);

    StepDispatcher::cooldown();
    cooldownTicks();

    expect(cooldownStateOf($nextPriority))->toBe(Dispatched::class)
        ->and(cooldownStateOf($heldPriority))->toBe(Pending::class);
});

it('holds the ungrouped lane as well', function (): void {
    $step = cooldownStep();
    $block = (string) Str::uuid();
    cooldownStep(['block_uuid' => $block, 'index' => 1, 'state' => Completed::class]);
    $next = cooldownStep(['block_uuid' => $block, 'index' => 2]);

    // The observer always assigns a lane on create; move the rows to the ungrouped lane.
    DB::table('steps')->update(['group' => null]);

    StepDispatcher::cooldown();
    cooldownTicks(null);

    expect(cooldownStateOf($step))->toBe(Pending::class)
        ->and(cooldownStateOf($next))->toBe(Dispatched::class);
});

it('is honoured by the steps:dispatch command', function (): void {
    $step = cooldownStep();

    StepDispatcher::cooldown();
    $this->artisan('steps:dispatch', ['--group' => 'alpha'])->assertSuccessful();

    expect(cooldownStateOf($step))->toBe(Pending::class);
});

/*
| Warmup
*/

it('dispatches held work again after a warmup', function (): void {
    $step = cooldownStep();

    StepDispatcher::cooldown();
    cooldownTicks();
    expect(cooldownStateOf($step))->toBe(Pending::class);

    StepDispatcher::warmup();
    cooldownTicks();

    expect(cooldownStateOf($step))->toBe(Dispatched::class)
        ->and(StepDispatcher::isCooldownActive())->toBeFalse()
        ->and(DB::table('steps_cooldowns')->count())->toBe(0);
});

it('does nothing when warming up without a cooldown', function (): void {
    StepDispatcher::warmup();
    StepDispatcher::warmup('trading');

    expect(DB::table('steps_cooldowns')->count())->toBe(0)
        ->and(StepDispatcher::isCooldownActive())->toBeFalse();
});

it('keeps the first cooldown time when cooling down twice', function (): void {
    $this->travelTo(now()->setTime(10, 0));
    StepDispatcher::cooldown();
    $first = Cooldown::since();

    $this->travelTo(now()->setTime(10, 5));
    StepDispatcher::cooldown();

    expect(DB::table('steps_cooldowns')->count())->toBe(1)
        ->and(Cooldown::since()?->format('H:i'))->toBe('10:00')
        ->and($first?->format('H:i'))->toBe('10:00');
});

it('treats a scope name with or without its trailing underscore as one scope', function (): void {
    StepDispatcher::cooldown('trading');
    StepDispatcher::cooldown('trading_');

    expect(DB::table('steps_cooldowns')->pluck('scope')->all())->toBe(['trading_'])
        ->and(StepDispatcher::isCooldownActive('trading_'))->toBeTrue();

    StepDispatcher::warmup('trading_');

    expect(StepDispatcher::isCooldownActive('trading'))->toBeFalse();
});

/*
| Scopes
*/

it('lists the default table set and every installed prefix as scopes', function (): void {
    expect(Cooldown::scopes())->toBe(['']);

    installTradingScope();

    expect(Cooldown::scopes())->toBe(['', 'trading_']);
});

it('holds only the scope that was cooled down', function (): void {
    installTradingScope();
    $default = cooldownStep();
    $trading = Steps::usingPrefix('trading', fn (): Step => cooldownStep());

    StepDispatcher::cooldown('trading');
    cooldownTicks();
    Steps::usingPrefix('trading', fn () => cooldownTicks());

    expect(cooldownStateOf($default))->toBe(Dispatched::class)
        ->and(Steps::usingPrefix('trading', fn (): string => cooldownStateOf($trading)))->toBe(Pending::class)
        ->and(StepDispatcher::isCooldownActive('trading'))->toBeTrue()
        ->and(StepDispatcher::isCooldownActive(''))->toBeFalse()
        ->and(StepDispatcher::isCooldownActive())->toBeFalse();
});

it('holds only the default scope when that one is cooled down', function (): void {
    installTradingScope();
    $default = cooldownStep();
    $trading = Steps::usingPrefix('trading', fn (): Step => cooldownStep());

    StepDispatcher::cooldown('');
    cooldownTicks();
    Steps::usingPrefix('trading', fn () => cooldownTicks());

    expect(cooldownStateOf($default))->toBe(Pending::class)
        ->and(Steps::usingPrefix('trading', fn (): string => cooldownStateOf($trading)))->toBe(Dispatched::class);
});

it('holds every scope during a global cooldown', function (): void {
    installTradingScope();
    $default = cooldownStep();
    $trading = Steps::usingPrefix('trading', fn (): Step => cooldownStep());

    StepDispatcher::cooldown();
    cooldownTicks();
    Steps::usingPrefix('trading', fn () => cooldownTicks());

    expect(cooldownStateOf($default))->toBe(Pending::class)
        ->and(Steps::usingPrefix('trading', fn (): string => cooldownStateOf($trading)))->toBe(Pending::class)
        ->and(StepDispatcher::isCooldownActive('trading'))->toBeTrue()
        ->and(StepDispatcher::isCooldownActive(''))->toBeTrue();
});

it('keeps a scope held by the global cooldown after that scope warms up', function (): void {
    installTradingScope();
    $trading = Steps::usingPrefix('trading', fn (): Step => cooldownStep());

    StepDispatcher::cooldown();
    StepDispatcher::cooldown('trading');
    StepDispatcher::warmup('trading');
    Steps::usingPrefix('trading', fn () => cooldownTicks());

    expect(Steps::usingPrefix('trading', fn (): string => cooldownStateOf($trading)))->toBe(Pending::class)
        ->and(StepDispatcher::isCooldownActive('trading'))->toBeTrue();
});

it('ends scope cooldowns too when warming up globally', function (): void {
    installTradingScope();
    StepDispatcher::cooldown('trading');
    StepDispatcher::cooldown('');

    StepDispatcher::warmup();

    expect(StepDispatcher::isCooldownActive('trading'))->toBeFalse()
        ->and(StepDispatcher::isCooldownActive(''))->toBeFalse()
        ->and(DB::table('steps_cooldowns')->count())->toBe(0);
});

it('leaves other scopes cooled when one scope warms up', function (): void {
    installTradingScope();
    StepDispatcher::cooldown('trading');
    StepDispatcher::cooldown('');

    StepDispatcher::warmup('trading');

    expect(StepDispatcher::isCooldownActive('trading'))->toBeFalse()
        ->and(StepDispatcher::isCooldownActive(''))->toBeTrue();
});

/*
| Cooling down versus cooled down
*/

it('reports cooling down while started work remains', function (array $started): void {
    $block = (string) Str::uuid();

    foreach ($started as $attributes) {
        cooldownStep(['block_uuid' => $block] + $attributes);
    }

    StepDispatcher::cooldown();

    expect(StepDispatcher::isInCoolDown())->toBeTrue()
        ->and(StepDispatcher::isInCoolDown(''))->toBeTrue()
        ->and(StepDispatcher::isCooledDown())->toBeFalse()
        ->and(StepDispatcher::isCooledDown(''))->toBeFalse();
})->with([
    'a step running' => [[['state' => Running::class]]],
    'a step handed to a worker' => [[['state' => Dispatched::class]]],
    'a next step still to run' => [[
        ['index' => 1, 'state' => Completed::class],
        ['index' => 2],
    ]],
    'a next step waiting for a later time' => [[
        ['index' => 1, 'state' => Completed::class],
        ['index' => 2, 'dispatch_after' => now()->addHour()],
    ]],
]);

it('does not hand out a started step before its time, but keeps waiting for it', function (): void {
    $block = (string) Str::uuid();
    cooldownStep(['block_uuid' => $block, 'index' => 1, 'state' => Completed::class]);
    $later = cooldownStep(['block_uuid' => $block, 'index' => 2, 'dispatch_after' => now()->addHour()]);

    StepDispatcher::cooldown();
    cooldownTicks();

    expect(cooldownStateOf($later))->toBe(Pending::class)
        ->and(StepDispatcher::isInCoolDown())->toBeTrue();

    $this->travel(2)->hours();
    cooldownTicks();

    expect(cooldownStateOf($later))->toBe(Dispatched::class);
});

it('reports fully cooled once only held or finished work is left', function (): void {
    cooldownStep(['state' => Completed::class]);
    cooldownStep();
    cooldownStep(['state' => Failed::class]);

    StepDispatcher::cooldown();

    expect(StepDispatcher::isInCoolDown())->toBeFalse()
        ->and(StepDispatcher::isCooledDown())->toBeTrue()
        ->and(StepDispatcher::isCooledDown(''))->toBeTrue();
});

it('moves from cooling down to cooled down when the last started step ends', function (): void {
    $running = cooldownStep(['state' => Running::class]);

    StepDispatcher::cooldown();
    expect(StepDispatcher::isInCoolDown())->toBeTrue();

    DB::table('steps')->where('id', $running->id)->update(['state' => Completed::class]);

    expect(StepDispatcher::isInCoolDown())->toBeFalse()
        ->and(StepDispatcher::isCooledDown())->toBeTrue();
});

it('reports neither cooling down nor cooled down without a cooldown', function (): void {
    cooldownStep(['state' => Running::class]);

    expect(StepDispatcher::isInCoolDown())->toBeFalse()
        ->and(StepDispatcher::isCooledDown())->toBeFalse()
        ->and(StepDispatcher::isInCoolDown(''))->toBeFalse()
        ->and(StepDispatcher::isCooledDown(''))->toBeFalse();
});

it('answers the global questions across every scope', function (): void {
    installTradingScope();
    $tradingRunning = Steps::usingPrefix('trading', fn (): Step => cooldownStep(['state' => Running::class]));

    StepDispatcher::cooldown('');

    // Only the default scope is held: not everything is cooled.
    expect(StepDispatcher::isInCoolDown())->toBeFalse()
        ->and(StepDispatcher::isCooledDown())->toBeFalse()
        ->and(StepDispatcher::isCooledDown(''))->toBeTrue();

    StepDispatcher::cooldown('trading');

    expect(StepDispatcher::isInCoolDown())->toBeTrue()
        ->and(StepDispatcher::isInCoolDown('trading'))->toBeTrue()
        ->and(StepDispatcher::isInCoolDown(''))->toBeFalse()
        ->and(StepDispatcher::isCooledDown())->toBeFalse();

    Steps::usingPrefix('trading', fn () => DB::table('trading_steps')
        ->where('id', $tradingRunning->id)
        ->update(['state' => Completed::class]));

    expect(StepDispatcher::isInCoolDown())->toBeFalse()
        ->and(StepDispatcher::isCooledDown())->toBeTrue();
});

/*
| Upgrade safety
*/

it('keeps dispatching on a host that has not run the cooldown migration yet', function (): void {
    Schema::drop((new StepsCooldown)->getTable());
    Cooldown::flushState();
    $step = cooldownStep();

    cooldownTicks();

    expect(cooldownStateOf($step))->toBe(Dispatched::class)
        ->and(StepDispatcher::isCooldownActive())->toBeFalse()
        ->and(StepDispatcher::isInCoolDown())->toBeFalse();
});

/*
| A cooldown that begins while a tick runs
*/

it('holds new work when the cooldown begins in the middle of a tick', function (): void {
    $step = cooldownStep();
    $cooled = false;

    // Start the cooldown right after the tick's first look at the cooldowns
    // table, so only the second look, just before steps are handed out, can see it.
    DB::listen(function ($query) use (&$cooled): void {
        if (! $cooled && str_contains($query->sql, 'from "steps_cooldowns"')) {
            $cooled = true;
            StepDispatcher::cooldown();
        }
    });

    StepDispatcher::dispatch('alpha');

    expect($cooled)->toBeTrue()
        ->and(cooldownStateOf($step))->toBe(Pending::class);
});

it('stays cooling down while a tick that began before the cooldown is still running', function (): void {
    $dispatcher = DB::table('steps_dispatcher')->where('group', 'alpha')->first();
    DB::table('steps_dispatcher')->where('id', $dispatcher->id)->update(['can_dispatch' => false, 'updated_at' => now()->subSeconds(5)]);

    StepDispatcher::cooldown();

    expect(StepDispatcher::isInCoolDown(''))->toBeTrue()
        ->and(StepDispatcher::isCooledDown(''))->toBeFalse();

    DB::table('steps_dispatcher')->where('id', $dispatcher->id)->update(['can_dispatch' => true, 'updated_at' => now()]);

    expect(StepDispatcher::isInCoolDown(''))->toBeFalse()
        ->and(StepDispatcher::isCooledDown(''))->toBeTrue();
});

it('ignores a tick lock taken after the cooldown began or left behind by a dead tick', function (string $lockedAt): void {
    StepDispatcher::cooldown();
    $this->travel(30)->seconds();

    $updatedAt = $lockedAt === 'after the cooldown' ? now() : now()->subSeconds(25);
    DB::table('steps_dispatcher')->where('group', 'alpha')->update(['can_dispatch' => false, 'updated_at' => $updatedAt]);

    expect(StepDispatcher::isCooledDown(''))->toBeTrue();
})->with(['after the cooldown', 'by a dead tick']);

/*
| Failure paths
*/

it('holds new work for one tick when a cooldown in force can no longer be read, then recovers', function (): void {
    $step = cooldownStep();
    StepDispatcher::cooldown();
    expect(StepDispatcher::isCooldownActive())->toBeTrue();

    Schema::drop('steps_cooldowns');

    StepDispatcher::dispatch('alpha');
    expect(cooldownStateOf($step))->toBe(Pending::class);

    StepDispatcher::dispatch('alpha');
    expect(cooldownStateOf($step))->toBe(Dispatched::class);
});

it('never holds a scope that was not cooled, even after another scope was seen cooled', function (): void {
    $default = cooldownStep();
    StepDispatcher::cooldown('trading');
    expect(StepDispatcher::isCooldownActive('trading'))->toBeTrue()
        ->and(StepDispatcher::isCooldownActive(''))->toBeFalse();
    expect(StepDispatcher::isCooldownActive('trading'))->toBeTrue(); // the last look saw a cooldown

    Schema::drop('steps_cooldowns');
    StepDispatcher::dispatch('alpha');

    expect(cooldownStateOf($default))->toBe(Dispatched::class);
});

it('never holds a host that has not cooled down, even when the cooldown lookup fails', function (): void {
    $step = cooldownStep();
    expect(StepDispatcher::isCooldownActive())->toBeFalse(); // the table is now known to exist

    Schema::drop('steps_cooldowns');
    StepDispatcher::dispatch('alpha');

    expect(cooldownStateOf($step))->toBe(Dispatched::class);
});

it('asks for a missing cooldowns table at most once a minute', function (): void {
    Schema::drop('steps_cooldowns');
    expect(StepDispatcher::isCooldownActive())->toBeFalse();

    $lookups = 0;
    DB::listen(function ($query) use (&$lookups): void {
        if (str_contains($query->sql, 'steps_cooldowns')) {
            $lookups++;
        }
    });

    cooldownTicks(ticks: 3);
    expect($lookups)->toBe(0);

    $this->travel(61)->seconds();
    StepDispatcher::isCooldownActive();
    expect($lookups)->toBe(1);
});

it('sees a cooldowns table created after the first look', function (): void {
    $migration = require __DIR__.'/../../database/migrations/2026_10_20_000000_create_steps_cooldowns_table.php';
    Schema::drop('steps_cooldowns');

    expect(StepDispatcher::isCooldownActive())->toBeFalse();

    $migration->up();
    $this->travel(61)->seconds();
    StepDispatcher::cooldown();

    expect(StepDispatcher::isCooldownActive())->toBeTrue();
});

/*
| Bookkeeping never pauses
*/

it('still fails a running parent whose child failed during the cooldown', function (): void {
    $childBlock = (string) Str::uuid();
    $parent = cooldownStep(['child_block_uuid' => $childBlock, 'state' => Running::class]);
    cooldownStep(['block_uuid' => $childBlock, 'state' => Failed::class]);

    StepDispatcher::cooldown();
    cooldownTicks(ticks: 5);

    expect(cooldownStateOf($parent))->toBe(Failed::class);
});

it('still cancels the waiting steps of a started block whose step failed', function (): void {
    $block = (string) Str::uuid();
    cooldownStep(['block_uuid' => $block, 'index' => 1, 'state' => Failed::class]);
    $next = cooldownStep(['block_uuid' => $block, 'index' => 2]);

    StepDispatcher::cooldown();
    cooldownTicks(ticks: 5);

    expect(cooldownStateOf($next))->not->toBe(Dispatched::class)
        ->and(cooldownStateOf($next))->not->toBe(Pending::class);
});

/*
| Started trees keep growing
*/

it('runs a child step that a running parent creates after the cooldown began', function (): void {
    $childBlock = (string) Str::uuid();
    cooldownStep(['child_block_uuid' => $childBlock, 'state' => Running::class]);

    StepDispatcher::cooldown();
    $child = cooldownStep(['block_uuid' => $childBlock]);
    cooldownTicks();

    expect(cooldownStateOf($child))->toBe(Dispatched::class);
});

it('runs the child of a started parent that lives in another group', function (): void {
    $childBlock = (string) Str::uuid();
    cooldownStep(['child_block_uuid' => $childBlock, 'state' => Running::class, 'group' => 'alpha']);
    $child = cooldownStep(['block_uuid' => $childBlock]);
    DB::table($child->getTable())->where('id', $child->id)->update(['group' => 'beta']);

    StepDispatcher::cooldown();
    cooldownTicks('beta');

    expect(cooldownStateOf($child))->toBe(Dispatched::class);
});

it('applies the rule in every group on its own', function (): void {
    $heldAlpha = cooldownStep(['group' => 'alpha']);
    $heldBeta = cooldownStep();
    DB::table($heldBeta->getTable())->where('id', $heldBeta->id)->update(['group' => 'beta']);

    $startedBlock = (string) Str::uuid();
    cooldownStep(['block_uuid' => $startedBlock, 'index' => 1, 'state' => Completed::class]);
    $runsBeta = cooldownStep(['block_uuid' => $startedBlock, 'index' => 2]);
    DB::table('steps')->where('block_uuid', $startedBlock)->update(['group' => 'beta']);

    StepDispatcher::cooldown();
    cooldownTicks('alpha');
    cooldownTicks('beta');

    expect(cooldownStateOf($heldAlpha))->toBe(Pending::class)
        ->and(cooldownStateOf($heldBeta))->toBe(Pending::class)
        ->and(cooldownStateOf($runsBeta))->toBe(Dispatched::class);
});

/*
| Large and odd trees
*/

it('finds the started trees of more blocks than one query chunk holds', function (): void {
    $children = [];
    for ($i = 0; $i < 620; $i++) {
        $childBlock = (string) Str::uuid();
        $children[] = $childBlock;
        DB::table('steps')->insert([
            ['class' => PrefixCarryingTestJob::class, 'block_uuid' => (string) Str::uuid(), 'child_block_uuid' => $childBlock, 'index' => 1, 'type' => 'default', 'queue' => 'default', 'group' => 'alpha', 'state' => Running::class, 'created_at' => now(), 'updated_at' => now()],
            ['class' => PrefixCarryingTestJob::class, 'block_uuid' => $childBlock, 'child_block_uuid' => null, 'index' => 1, 'type' => 'default', 'queue' => 'default', 'group' => 'alpha', 'state' => Pending::class, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
    $held = cooldownStep();

    StepDispatcher::cooldown();
    $allowed = Cooldown::dispatchableBlocks('alpha');

    expect($allowed)->toHaveCount(620)
        ->and(array_diff($children, $allowed))->toBe([])
        ->and($allowed)->not->toContain($held->block_uuid);
});

it('stops walking a tree whose blocks point at each other and holds it', function (): void {
    $a = (string) Str::uuid();
    $b = (string) Str::uuid();
    $stepA = cooldownStep(['block_uuid' => $a, 'child_block_uuid' => $b]);
    $stepB = cooldownStep(['block_uuid' => $b, 'child_block_uuid' => $a]);

    StepDispatcher::cooldown();
    cooldownTicks();

    expect(cooldownStateOf($stepA))->toBe(Pending::class)
        ->and(cooldownStateOf($stepB))->toBe(Pending::class);
});

/*
| Status details
*/

it('reports the earliest start when both a global and a scope cooldown hold a scope', function (): void {
    $this->freezeSecond();
    StepDispatcher::cooldown('');
    $this->travel(10)->minutes();
    StepDispatcher::cooldown();

    expect(Cooldown::since('')->toDateTimeString())->toBe(now()->subMinutes(10)->toDateTimeString())
        ->and(Cooldown::since()->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('does not list a dispatcher table without its steps table as a scope', function (): void {
    Schema::create('foo_steps_dispatcher', fn ($table) => $table->id());

    expect(Cooldown::scopes())->toBe(['']);
});

it('keeps the no-progress alarm quiet while a cooldown holds the work', function (): void {
    Event::fake([StaleStepsDetected::class]);

    $waiting = cooldownStep();
    DB::table('steps')->where('id', $waiting->id)->update(['created_at' => now()->subMinutes(20)]);
    $finished = cooldownStep(['state' => Completed::class]);
    DB::table('steps')->where('id', $finished->id)->update(['updated_at' => now()->subMinutes(20)]);

    StepDispatcher::cooldown();
    $this->artisan('steps:recover-stale', ['--watchdog-progress' => true, '--progress-threshold' => 600])->assertSuccessful();
    Event::assertNotDispatched(StaleStepsDetected::class, fn ($event): bool => $event->reason === 'group_no_progress');

    StepDispatcher::warmup();
    $this->artisan('steps:recover-stale', ['--watchdog-progress' => true, '--progress-threshold' => 600])->assertSuccessful();
    Event::assertDispatched(StaleStepsDetected::class, fn ($event): bool => $event->reason === 'group_no_progress');
});
