<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\Tests\Fixtures\ObsoleteAttemptTestJob;

/*
|--------------------------------------------------------------------------
| Diagnostic logging
|--------------------------------------------------------------------------
|
| The per-step file logs an operator turns on to understand a wedge. Off, they
| must not touch the disk at all. On, each documented file must really appear.
|
*/

beforeEach(function (): void {
    $this->logPath = sys_get_temp_dir().'/sd-diagnostic-log-'.Str::uuid();

    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());
    config()->set('step-dispatcher.logging.enabled', true);
    config()->set('step-dispatcher.logging.path', $this->logPath);

    ObsoleteAttemptTestJob::reset();
});

afterEach(function (): void {
    File::deleteDirectory($this->logPath);
    ObsoleteAttemptTestJob::reset();
});

function loggedStep(): Step
{
    return Step::create([
        'class' => ObsoleteAttemptTestJob::class,
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
        'type' => 'default',
        'queue' => 'default',
        'group' => 'logging-test',
        'state' => Pending::class,
    ]);
}

function runLoggedJob(Step $step): void
{
    $job = new ObsoleteAttemptTestJob;
    $job->step = $step;
    $job->handle();
}

it('writes nothing to disk when logging is off', function (): void {
    config()->set('step-dispatcher.logging.enabled', false);

    Step::log(1, 'states', 'line');
    Step::logGlobal('dispatcher', 'line');
    runLoggedJob(loggedStep());

    expect(is_dir($this->logPath))->toBeFalse();
});

it('writes a step line to its own folder with a timestamp', function (): void {
    Step::log(7, 'states', "Running → Pending\n");

    expect(File::get($this->logPath.'/steps/7/states.log'))
        ->toMatch('/^\d{2}:\d{2}:\d{2}\.\d{6} \| Running → Pending\n$/');
});

it('writes tick events and lines with no step to a global file', function (): void {
    Step::logGlobal('dispatcher', 'Tick started');
    Step::log(null, 'dispatcher', 'No step context');

    expect(File::get($this->logPath.'/dispatcher.log'))
        ->toContain('Tick started')
        ->toContain('No step context');
});

it('keeps a channel name from leaving the log folder', function (): void {
    Step::log(7, '../../escape', 'line');

    expect(File::exists($this->logPath.'/steps/7/escape.log'))->toBeTrue()
        ->and(File::exists($this->logPath.'/escape.log'))->toBeFalse();
});

it('fills each documented file as a step runs', function (Closure $act, string $file, string $expected): void {
    ObsoleteAttemptTestJob::$act = $act;

    $step = loggedStep();

    runLoggedJob($step);

    expect($step->fresh()->getLogContents($file))->toContain($expected);
})->with([
    'states' => [static function (): void {}, 'states', '→ Running'],
    'retries' => [static fn ($job) => $job->retryJob(), 'retries', 'Retry scheduled'],
    'throttled' => [static fn ($job) => $job->rescheduleWithoutRetry(), 'throttled', 'Throttled'],
    'exceptions' => [static function (): void {
        throw new RuntimeException('boom');
    }, 'exceptions', 'Caught RuntimeException'],
]);

it('removes the step folder when the step is deleted through its model', function (): void {
    $step = loggedStep();
    Step::log($step->id, 'states', 'line');

    $step->delete();

    expect(is_dir($this->logPath.'/steps/'.$step->id))->toBeFalse();
});
