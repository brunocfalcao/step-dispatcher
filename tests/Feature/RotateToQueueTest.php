<?php

declare(strict_types=1);

use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\Tests\Fixtures\RotatableTestJob;

beforeEach(function () {
    // StepObserver::saving() falls a step's queue back to 'default' if
    // the queue name isn't in the `step-dispatcher.queues.valid` allowlist.
    // The rotateToQueue feature deliberately targets per-hostname worker
    // queues (eos / iris / nyx) — declare them valid so the observer
    // doesn't strip the rotation target during save.
    config()->set('step-dispatcher.queues.valid', ['eos', 'iris', 'nyx']);

    // Step::log() writes to per-step files under storage/logs/steps/{id}/
    // but only when logging is enabled. Route it to a sandbox temp dir
    // so the test can read the rotation log line back without polluting
    // the host machine.
    config()->set('step-dispatcher.logging.enabled', true);
    config()->set('step-dispatcher.logging.path', sys_get_temp_dir().'/step-dispatcher-test-'.uniqid());
});

/**
 * Create a Step that has already been picked up by a worker — i.e.
 * Pending → Running → (worker now realises its IP is blacklisted and
 * calls rotateToQueue from within compute()). The Running state is
 * the realistic source of every rotateToQueue invocation in production.
 */
function makeRunningStep(string $blockUuid, string $queue = 'eos', int $retries = 0): Step
{
    $step = Step::create([
        'class' => RotatableTestJob::class,
        'block_uuid' => $blockUuid,
        'index' => 1,
        'type' => 'default',
        'queue' => $queue,
        'group' => 'test-group',
        'retries' => $retries,
        'started_at' => now()->subSeconds(2),
    ]);

    $step->state->transitionTo(Running::class);

    return $step->fresh();
}

it('rotateToQueue updates the step queue without consuming a retry', function () {
    $step = makeRunningStep('rotate-block-uuid', 'eos', 3);

    $job = new RotatableTestJob;
    $job->step = $step;

    $job->callRotateToQueue('iris');

    $step->refresh();

    expect($step->queue)->toBe('iris')
        ->and($step->retries)->toBe(3)
        ->and($step->started_at)->toBeNull()
        ->and($step->state)->toBeInstanceOf(Pending::class);
});

it('rotateToQueue records a per-step rotation log naming the previous queue', function () {
    $step = makeRunningStep('rotate-log-uuid', 'eos', 1);

    $job = new RotatableTestJob;
    $job->step = $step;

    $job->callRotateToQueue('nyx');

    $contents = $step->fresh()->getLogContents('rotated');

    expect($contents)->not->toBeNull()
        ->and($contents)->toContain('rotated')
        ->and($contents)->toContain('eos')
        ->and($contents)->toContain('nyx');
});

it('rotateToQueue marks stepStatusUpdated so downstream lifecycle does not re-transition', function () {
    $step = makeRunningStep('rotate-status-uuid');

    $job = new RotatableTestJob;
    $job->step = $step;

    expect($job->stepStatusUpdated)->toBeFalse();

    $job->callRotateToQueue('iris');

    expect($job->stepStatusUpdated)->toBeTrue();
});
