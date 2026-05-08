<?php

declare(strict_types=1);

use StepDispatcher\Support\RuntimeContext;
use StepDispatcher\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// Wipe the RuntimeContext prefix stack between tests. A test that
// pushes a prefix and throws before popping (or simply forgets the
// closing pop) would otherwise leak the prefix into the next test
// and silently route writes to the wrong table set. Cheap insurance.
afterEach(function (): void {
    app(RuntimeContext::class)->reset();
});
