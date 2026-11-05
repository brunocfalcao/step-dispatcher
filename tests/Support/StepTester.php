<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Support;

use Illuminate\Support\Collection;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\StepDispatcher;

/**
 * Drives whole workflows through real dispatcher ticks and asserts the exact
 * state of every step after each tick.
 *
 * A status matrix maps a tick number to the expected state of each step id.
 * The first tick must list every step. A later tick lists only what changes;
 * every other step must keep the state it had after the previous tick.
 */
final class StepTester
{
    /** @var Collection<int, Step> */
    private Collection $steps;

    /** @var array<int, array<int, string>> */
    private array $statusMatrix = [];

    private ?int $limitTicks = null;

    private ?string $label = null;

    /**
     * @param  array<int, Step>  $steps
     */
    public static function withSteps(array $steps): self
    {
        $instance = new self;
        $instance->steps = collect($steps);

        return $instance;
    }

    /**
     * @param  array<int, array<string, mixed>>  $definitions
     * @return array<int, Step>
     */
    public static function createSteps(array $definitions, string $jobClass): array
    {
        return collect($definitions)->map(static function (array $overrides) use ($jobClass): Step {
            /** @var Step $step */
            $step = Step::factory()->create(array_merge([
                'state' => Pending::class,
                'class' => $jobClass,
            ], $overrides));

            return $step;
        })->all();
    }

    /**
     * @param  array<int, array<int, string>>  $matrix
     */
    public function withStatusMatrix(array $matrix): self
    {
        $this->statusMatrix = $matrix;

        return $this;
    }

    public function onlyDispatchTicks(int $count): self
    {
        $this->limitTicks = $count;

        return $this;
    }

    public function withLabel(string $name): self
    {
        $this->label = $name;

        return $this;
    }

    public function test(): void
    {
        $ticks = array_slice($this->statusMatrix, 0, $this->limitTicks, preserve_keys: true);

        expect($ticks)->not->toBeEmpty('A status matrix needs at least one tick.');

        $dispatchGroup = $this->steps->first()?->group;
        $previousStatuses = [];

        foreach ($ticks as $tick => $changes) {
            StepDispatcher::dispatch($dispatchGroup);

            $expectedStatuses = $changes + $previousStatuses;

            foreach ($this->steps as $step) {
                $actual = Step::find($step->id)?->state->value() ?? 'missing';

                expect($actual)->toBe(
                    $expectedStatuses[$step->id] ?? 'not listed in the matrix',
                    sprintf('Scenario [%s], tick %s, step %s.', $this->label ?? 'unlabelled', $tick, $step->id),
                );

                $previousStatuses[$step->id] = $actual;
            }
        }
    }
}
