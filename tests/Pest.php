<?php

declare(strict_types=1);

use StepDispatcher\Models\Step;
use StepDispatcher\Support\RuntimeContext;
use StepDispatcher\Tests\Collision\CollisionTestCase;
use StepDispatcher\Tests\TestCase;

pest()->tia()
    ->always()
    ->locally()
    ->filtered();

uses(TestCase::class)->in('Feature', 'Unit');

// Multi-process collision tests run against a real MySQL server, outside the
// SQLite in-memory suite and without its frozen clock or wrapping transaction.
uses(CollisionTestCase::class)->in('Collision');

// Workflow scenarios assert exact states tick by tick, so the clock stays still.
//
// Group round-robin orders by the database clock. SQLite reads it in whole
// milliseconds where MySQL reads microseconds, so two steps created inside one
// millisecond would tie and break the rotation. The short wait rules that out.
uses()->beforeEach(function (): void {
    $this->freezeTime();
    config()->set('step-dispatcher.flag_path', sys_get_temp_dir());

    Step::creating(static function (): void {
        usleep(1500);
    });
})->in('Feature/Scenarios');

// Wipe the RuntimeContext prefix stack between tests. A test that
// pushes a prefix and throws before popping (or simply forgets the
// closing pop) would otherwise leak the prefix into the next test
// and silently route writes to the wrong table set. Cheap insurance.
afterEach(function (): void {
    app(RuntimeContext::class)->reset();
});
