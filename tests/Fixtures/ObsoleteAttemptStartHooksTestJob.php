<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use Closure;
use Illuminate\Support\Facades\DB;
use StepDispatcher\Abstracts\BaseStepJob;
use StepDispatcher\Models\Step;

/**
 * Test fixture for a worker that loses its step during one of the checks that
 * run before the work starts. The work itself must then never begin: for an
 * exchange-facing job, that work is the order.
 */
final class ObsoleteAttemptStartHooksTestJob extends BaseStepJob
{
    public int $retries = 5;

    public static string $moveDuring = 'startOrStop';

    public static bool $passes = true;

    public static ?Closure $move = null;

    public static bool $computed = false;

    public static bool $preWorkRan = false;

    /** @var array<string, mixed>|null */
    public static ?array $rowAfterMove = null;

    /** @var list<string> */
    public static array $hooksAfterMove = [];

    public static function reset(): void
    {
        self::$moveDuring = 'startOrStop';
        self::$passes = true;
        self::$move = null;
        self::$computed = false;
        self::$preWorkRan = false;
        self::$rowAfterMove = null;
        self::$hooksAfterMove = [];
    }

    public function startOrStop(): bool
    {
        return $this->check('startOrStop');
    }

    public function startOrFail(): bool
    {
        return $this->check('startOrFail');
    }

    public function startOrSkip(): bool
    {
        return $this->check('startOrSkip');
    }

    public function startOrRetry(): bool
    {
        return $this->check('startOrRetry');
    }

    /**
     * Stands in for consumers that do real work here before compute(): Taxiny
     * enters the receipt's document lock, Kraite records an API dispatch.
     */
    protected function executeJobLogic(): void
    {
        self::$preWorkRan = true;

        parent::executeJobLogic();
    }

    protected function compute(): mixed
    {
        self::$computed = true;

        return ['from' => 'this attempt'];
    }

    private function check(string $name): bool
    {
        if (self::$rowAfterMove !== null) {
            self::$hooksAfterMove[] = $name;

            return true;
        }

        if ($name !== self::$moveDuring) {
            return true;
        }

        (self::$move)((int) $this->step->id);

        self::$rowAfterMove = (array) DB::table(Step::tableName())->where('id', $this->step->id)->first();

        return self::$passes;
    }
}
