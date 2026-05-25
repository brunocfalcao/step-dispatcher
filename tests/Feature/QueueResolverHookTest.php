<?php

declare(strict_types=1);

use StepDispatcher\Exceptions\NoCleanWorkerException;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Failed;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\StepDispatcher;
use StepDispatcher\Tests\Fixtures\PrefixCarryingTestJob;

/*
|--------------------------------------------------------------------------
| Queue Resolver Hook
|--------------------------------------------------------------------------
|
| Consumer apps (e.g. Kraite) register a closure via
| `StepDispatcher::setQueueResolver()`. The dispatcher calls the closure
| at push time with the Step about to be dispatched. The closure's return
| value (string) overrides `step.queue` for the actual Redis push AND is
| persisted to the DB row.
|
| - Return value of `null` means "no opinion" — fall through to the step's
|   existing queue value (no override).
| - Throwing `NoCleanWorkerException` signals a terminal failure: the
|   dispatcher's existing try/catch transitions the step to Failed.
|
| The hook is OPTIONAL — when no resolver is registered, the dispatcher
| behaves exactly as it did before this feature shipped (verified by the
| pre-existing 63-test framework suite staying green).
|
*/

beforeEach(function (): void {
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    config()->set('step-dispatcher.queues.valid', ['default', 'sync', 'positions', 'positions-eos', 'positions-iris', 'positions-nyx']);

    // Reset the resolver between tests so test order doesn't matter.
    StepDispatcher::setQueueResolver(null);
});

afterEach(function (): void {
    StepDispatcher::setQueueResolver(null);
});

/**
 * Build a Pending step ready for dispatcher::dispatch() to pick up,
 * wired to the PrefixCarryingTestJob fixture (already used elsewhere
 * in the suite — a no-op job that just records its compute() ran).
 */
function makePendingStep(string $queue = 'default', string $group = 'test-group'): Step
{
    return Step::create([
        'class' => PrefixCarryingTestJob::class,
        'block_uuid' => 'resolver-test-'.uniqid(),
        'index' => 1,
        'type' => 'default',
        'queue' => $queue,
        'group' => $group,
        'state' => Pending::class,
    ]);
}

describe('Resolver registration', function (): void {
    it('accepts a closure via setQueueResolver and stores it', function (): void {
        $called = false;
        StepDispatcher::setQueueResolver(function (Step $step) use (&$called): ?string {
            $called = true;

            return null;
        });

        $step = makePendingStep('default');
        StepDispatcher::dispatch('test-group');

        expect($called)->toBeTrue();
    });

    it('can unset the resolver by passing null', function (): void {
        $called = false;
        StepDispatcher::setQueueResolver(function (Step $step) use (&$called): ?string {
            $called = true;

            return null;
        });

        StepDispatcher::setQueueResolver(null);

        $step = makePendingStep('default');
        StepDispatcher::dispatch('test-group');

        expect($called)->toBeFalse();
    });
});

describe('Resolver override semantics', function (): void {
    it('overrides step.queue with the returned value and persists to the database', function (): void {
        StepDispatcher::setQueueResolver(static fn (Step $step): string => 'positions-eos');

        $step = makePendingStep('positions');
        StepDispatcher::dispatch('test-group');

        $step->refresh();
        expect($step->queue)->toBe('positions-eos');
    });

    it('returning null leaves step.queue unchanged', function (): void {
        StepDispatcher::setQueueResolver(static fn (Step $step): ?string => null);

        $step = makePendingStep('positions');
        StepDispatcher::dispatch('test-group');

        $step->refresh();
        expect($step->queue)->toBe('positions');
    });

    it('passes the Step instance to the closure so consumers can inspect arguments', function (): void {
        $receivedStepId = null;
        StepDispatcher::setQueueResolver(function (Step $step) use (&$receivedStepId): ?string {
            $receivedStepId = $step->id;

            return null;
        });

        $step = makePendingStep('default');
        StepDispatcher::dispatch('test-group');

        expect($receivedStepId)->toBe($step->id);
    });
});

describe('Terminal failure via NoCleanWorkerException', function (): void {
    it('transitions the step to Failed when the resolver throws NoCleanWorkerException', function (): void {
        StepDispatcher::setQueueResolver(static function (Step $step): string {
            throw new NoCleanWorkerException('all workers banned for test scenario');
        });

        $step = makePendingStep('positions');
        StepDispatcher::dispatch('test-group');

        $step->refresh();
        expect($step->state)->toBeInstanceOf(Failed::class);
    });

    it('records the exception message on the failed step for triage', function (): void {
        StepDispatcher::setQueueResolver(static function (Step $step): string {
            throw new NoCleanWorkerException('all workers banned for test scenario');
        });

        $step = makePendingStep('positions');
        StepDispatcher::dispatch('test-group');

        $step->refresh();
        expect($step->error_message)->toContain('all workers banned for test scenario');
    });
});

describe('No-resolver baseline', function (): void {
    it('when no resolver is registered, step.queue is dispatched verbatim', function (): void {
        // Resolver explicitly NOT set (cleared in beforeEach).

        $step = makePendingStep('default');
        StepDispatcher::dispatch('test-group');

        $step->refresh();
        expect($step->queue)->toBe('default');
    });
});

describe('Sync queue bypass', function (): void {
    it('does not invoke the resolver when step.queue is sync', function (): void {
        $called = false;
        StepDispatcher::setQueueResolver(function (Step $step) use (&$called): string {
            $called = true;

            return 'positions-eos';
        });

        $step = makePendingStep('sync');
        StepDispatcher::dispatch('test-group');

        expect($called)->toBeFalse();
    });
});

describe('Retry re-fires the resolver', function (): void {
    it('invokes the resolver again when a previously-dispatched step returns to Pending and dispatches again', function (): void {
        $callCount = 0;
        StepDispatcher::setQueueResolver(function (Step $step) use (&$callCount): string {
            $callCount++;

            // Alternate the resolved queue per call so we can assert the
            // second resolution actually changed the row, not just that
            // the count incremented.
            return $callCount === 1 ? 'positions-eos' : 'positions-iris';
        });

        $step = makePendingStep('positions');

        // First dispatch — resolver picks eos, step is now Dispatched.
        StepDispatcher::dispatch('test-group');

        $step->refresh();
        expect($step->queue)->toBe('positions-eos');

        // Send the step back to Pending (simulates retryJob) so the next
        // dispatcher tick can re-pick it up. Set is_throttled=true to
        // bypass the retries++ in RunningToPending — we don't care about
        // retry counter semantics here, only about the resolver firing.
        $step->state = new Pending($step);
        $step->is_throttled = true;
        $step->save();

        // Second dispatch — resolver picks iris this time.
        StepDispatcher::dispatch('test-group');

        $step->refresh();
        expect($callCount)->toBe(2)
            ->and($step->queue)->toBe('positions-iris');
    });
});
