<?php

declare(strict_types=1);

namespace StepDispatcher\Tests\Fixtures;

use Closure;
use Illuminate\Support\Facades\DB;
use StepDispatcher\Abstracts\BaseStepJob;
use StepDispatcher\Models\Step;
use Throwable;

/**
 * Test fixture for a worker that loses its step while it is still running.
 * It implements every consumer hook and can take the row away during any of
 * them, so a test can pin that no write and no later hook follows, and can
 * also drive an attempt that keeps its step to pin the normal behaviour.
 */
final class ObsoleteAttemptTestJob extends BaseStepJob
{
    public int $retries = 5;

    public static string $moveDuring = 'compute';

    public static ?Closure $move = null;

    public static ?Closure $act = null;

    public static mixed $result = ['from' => 'this attempt'];

    public static bool $doubleCheckPasses = true;

    public static bool $confirms = true;

    public static bool $retriesException = false;

    public static bool $ignoresException = false;

    /** @var array<string, mixed>|null */
    public static ?array $rowAfterMove = null;

    /** @var list<string> */
    public static array $hooks = [];

    /** @var list<string> */
    public static array $hooksAfterMove = [];

    public static function reset(): void
    {
        self::$moveDuring = 'compute';
        self::$move = null;
        self::$act = null;
        self::$result = ['from' => 'this attempt'];
        self::$doubleCheckPasses = true;
        self::$confirms = true;
        self::$retriesException = false;
        self::$ignoresException = false;
        self::$rowAfterMove = null;
        self::$hooks = [];
        self::$hooksAfterMove = [];
    }

    protected function compute(): mixed
    {
        $this->hook('compute');

        if (self::$act !== null) {
            (self::$act)($this);
        }

        return self::$result;
    }

    public function doubleCheck(): bool
    {
        $this->hook('doubleCheck');

        return self::$doubleCheckPasses;
    }

    public function confirmOrRetry(): bool
    {
        $this->hook('confirmOrRetry');

        return self::$confirms;
    }

    public function complete(): void
    {
        $this->hook('complete');
    }

    public function retryException(Throwable $e): bool
    {
        $this->hook('retryException');

        return self::$retriesException;
    }

    public function ignoreException(Throwable $e): bool
    {
        $this->hook('ignoreException');

        return self::$ignoresException;
    }

    public function resolveException(Throwable $e): void
    {
        $this->hook('resolveException');
    }

    protected function onExceptionLogged(Throwable $e): void
    {
        $this->hook('onExceptionLogged');
    }

    protected function externalRetryException(Throwable $e): bool
    {
        $this->hook('externalRetryException');

        return false;
    }

    protected function externalIgnoreException(Throwable $e): bool
    {
        $this->hook('externalIgnoreException');

        return false;
    }

    protected function externalResolveException(Throwable $e): void
    {
        $this->hook('externalResolveException');
    }

    private function hook(string $name): void
    {
        self::$hooks[] = $name;

        if (self::$rowAfterMove !== null) {
            self::$hooksAfterMove[] = $name;

            return;
        }

        if (self::$move === null || $name !== self::$moveDuring) {
            return;
        }

        (self::$move)((int) $this->step->id);

        self::$rowAfterMove = (array) DB::table(Step::tableName())->where('id', $this->step->id)->first();
    }
}
