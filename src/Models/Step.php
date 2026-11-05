<?php

declare(strict_types=1);

namespace StepDispatcher\Models;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\ModelStates\HasStates;
use StepDispatcher\Abstracts\BaseModel;
use StepDispatcher\Abstracts\StepStatus;
use StepDispatcher\Concerns\Step\HasActions;
use StepDispatcher\Concerns\Step\HasStepLogging;
use StepDispatcher\Database\Factories\StepFactory;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Dispatched;
use StepDispatcher\States\Failed;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\States\Skipped;
use StepDispatcher\States\Stopped;
use StepDispatcher\Support\RuntimeContext;
use StepDispatcher\Support\Steps;
use Throwable;

/**
 * @property int $id
 * @property string $block_uuid
 * @property string $type
 * @property StepStatus $state
 * @property string|null $class
 * @property string|null $label
 * @property int|null $index
 * @property array|null $response
 * @property string|null $error_message
 * @property string|null $error_stack_trace
 * @property string|null $relatable_type
 * @property int|null $relatable_id
 * @property string|null $child_block_uuid
 * @property string $execution_mode
 * @property int $double_check
 * @property string $queue
 * @property string $priority
 * @property array|null $arguments
 * @property int $retries
 * @property Carbon|null $dispatch_after
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property int $duration
 * @property string|null $hostname
 * @property bool $was_notified
 * @property string|null $workflow_id
 * @property string|null $canonical
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read string|null $group
 */
final class Step extends BaseModel
{
    use HasActions, HasFactory, HasStates, HasStepLogging;

    protected $guarded = [];

    /**
     * Keep retry due times at the same millisecond precision as the
     * dispatch_after schema. Second-format Eloquent serialization silently
     * rounds a positive jobBackoffMs value before the worker can observe it.
     * Older second-precision schemas safely truncate the fractional part.
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $casts = [
        'arguments' => 'array',
        'response' => 'array',

        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'dispatch_after' => 'datetime',

        'was_throttled' => 'boolean',
        'is_throttled' => 'boolean',
        'exception_analysed' => 'boolean',

        'state' => StepStatus::class,
    ];

    /**
     * The row locks this process holds, innermost last. Each one is a
     * transaction that has begun and not yet been committed.
     *
     * @var list<array{id: mixed, connection: Connection}>
     */
    private static array $rowLockHolds = [];

    /**
     * Run a write with this row locked, from the read it decides on to its save.
     *
     * The lock only orders competing writers, and it ends the moment the write
     * saves a new state. A project's reaction to that state then runs with the
     * result committed, exactly where it runs in a save with no lock, so a
     * reaction that fails cannot take the result with it. That includes a failed
     * query on Postgres, which aborts the whole transaction around it.
     *
     * A write that throws keeps what it already saved, and the failure is thrown
     * again once the lock is released. The one exception is a failed statement
     * before the state save, such as a query in a saving, updating or non-state
     * updated listener: on Postgres it aborts the lock's transaction, so the
     * earlier saves are lost and the state does not move.
     *
     * For the engine's own writes only. A write must save the state of the row it
     * locked last, and must not open its own transaction around that save.
     *
     * @internal
     *
     * @param  Closure(?self): mixed  $write
     */
    public static function whileRowLocked(mixed $id, Closure $write): mixed
    {
        $connection = (new self)->getConnection();
        $connection->beginTransaction();

        $depth = count(self::$rowLockHolds);
        self::$rowLockHolds[] = ['id' => $id, 'connection' => $connection];

        try {
            $result = $write(self::query()->whereKey($id)->lockForUpdate()->first());
        } catch (Throwable $e) {
            try {
                self::releaseRowLocks($depth);
            } catch (Throwable) {
                // The failure already being reported is the one that matters.
            }

            throw $e;
        }

        self::releaseRowLocks($depth);

        return $result;
    }

    /**
     * Commit every lock taken above this depth, innermost first. The state save
     * may already have released them, which leaves nothing to do here.
     */
    private static function releaseRowLocks(int $depth): void
    {
        while (count(self::$rowLockHolds) > $depth) {
            $hold = array_pop(self::$rowLockHolds);

            try {
                $hold['connection']->commit();
            } catch (Throwable $e) {
                try {
                    $hold['connection']->rollBack();
                } catch (Throwable) {
                    // The failed commit is the one that matters.
                }

                throw $e;
            }
        }
    }

    /**
     * End the lock on this row once its new state is saved, before any listener
     * hears about it. Eloquent has run the UPDATE by the time 'updated' fires.
     *
     * @param  string  $event
     * @param  bool  $halt
     * @return mixed
     */
    protected function fireModelEvent($event, $halt = true)
    {
        if ($event === 'updated' && self::$rowLockHolds !== [] && $this->wasChanged('state')) {
            foreach (self::$rowLockHolds as $depth => $hold) {
                if ((string) $hold['id'] === (string) $this->getKey()) {
                    self::releaseRowLocks($depth);

                    break;
                }
            }
        }

        return parent::fireModelEvent($event, $halt);
    }

    /**
     * Static accessor used by raw-SQL sites (recursive CTEs,
     * INSERT INTO statements, DB::table calls) that can't go
     * through Eloquent. Returns the table name with the active
     * runtime prefix applied.
     */
    public static function tableName(): string
    {
        return app(RuntimeContext::class)->current().'steps';
    }

    /**
     * Single-call explicit prefix override. Returns a fresh query
     * builder bound to the prefixed table, so a one-off cross-prefix
     * write does not require pushing onto the runtime stack:
     *
     *     Step::prefix('calc')->create([...]);
     *
     * The base table name is computed from the explicit prefix
     * (normalised to its trailing-underscore form), independent of
     * whatever ambient prefix the surrounding code was running under.
     */
    public static function prefix(string $prefix): Builder
    {
        $instance = new self;
        $instance->setTable(Steps::normalise($prefix).'steps');

        return $instance->newQuery();
    }

    /**
     * @param  class-string|array<int, class-string>  $classes
     */
    public static function hasLiveWorkflow(Model $relatable, string|array $classes): bool
    {
        return self::query()
            ->forClasses($classes)
            ->forRelatable($relatable)
            ->inProgress()
            ->exists();
    }

    public static function concludedStepStates()
    {
        return [Completed::class, Skipped::class];
    }

    public static function failedStepStates()
    {
        return [Failed::class, Stopped::class];
    }

    public static function terminalStepStates(): array
    {
        return [
            Completed::class,
            Skipped::class,
            Cancelled::class,
            Failed::class,
            Stopped::class,
        ];
    }

    /**
     * States a step can rest in forever: every terminal state plus
     * NotRunnable (a parked resolve-exception that was never promoted —
     * its block concluded without needing it). This is the settled-tree
     * contract shared by steps:archive and steps:purge; the two lists
     * drifting apart left NotRunnable trees archivable but never
     * purgeable.
     */
    public static function settledStepStates(): array
    {
        return array_merge(self::terminalStepStates(), [NotRunnable::class]);
    }

    /**
     * Get a random dispatch group from available groups.
     * Delegates to StepsDispatcher::getDispatchGroup().
     */
    public static function getDispatchGroup(): ?string
    {
        return StepsDispatcher::getDispatchGroup();
    }

    /**
     * Scope a query to the dispatcher's group lane. `null` means the
     * NULL-group lane (group IS NULL) — never "all groups". Every query
     * inside a dispatcher tick must use this scope so the dispatch phase
     * and the cleanup phases agree on what a tick owns; a null-lane tick
     * sweeping parents from named groups would race the per-group ticks
     * that own them (the CAS lock only serializes ticks of the same group).
     */
    public function scopeForGroup(Builder $query, ?string $group): Builder
    {
        return $group === null
            ? $query->whereNull('group')
            : $query->where('group', $group);
    }

    /**
     * @param  class-string|array<int, class-string>  $classes
     */
    public function scopeForClasses(Builder $query, string|array $classes): Builder
    {
        $column = $query->getModel()->qualifyColumn('class');

        return is_array($classes)
            ? $query->whereIn($column, $classes)
            : $query->where($column, $classes);
    }

    /**
     * Steps not in a terminal state. NotRunnable deliberately remains here:
     * it is settled, but it is not a terminal state in the dispatcher state
     * machine.
     */
    public function scopeNonTerminal(Builder $query): Builder
    {
        return $query->whereNotIn(
            $query->getModel()->qualifyColumn('state'),
            self::terminalStepStates(),
        );
    }

    /**
     * Steps that can still be picked up or are currently executing.
     */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->whereIn(
            $query->getModel()->qualifyColumn('state'),
            [Pending::class, Dispatched::class, Running::class],
        );
    }

    public function scopeForRelatable(Builder $query, Model $relatable): Builder
    {
        $model = $query->getModel();

        return $query
            ->where($model->qualifyColumn('relatable_type'), $relatable->getMorphClass())
            ->where($model->qualifyColumn('relatable_id'), $relatable->getKey());
    }

    /**
     * Mark this step's exception as analysed — the operator has triaged the
     * failure and doesn't want it surfaced again. Deliberately NOT a state
     * (the step stays Failed); it's a triage flag on top of the state machine.
     */
    public function exceptionWasAnalysed(): void
    {
        $this->update(['exception_analysed' => true]);
    }

    /**
     * Persist a diagnosis (e.g. an AI-generated verdict) alongside the
     * failure so it can be re-read later. Storing a verdict does not mark
     * the exception analysed — reading a diagnosis and resolving the
     * failure are separate operator actions.
     */
    public function storeExceptionVerdict(string $verdict): void
    {
        $this->update(['exception_verdict' => $verdict]);
    }

    /**
     * Resolve the live table name. Honours an explicit `setTable()`
     * override on the instance (e.g. set by `Step::prefix('calc')`)
     * before falling back to the active runtime prefix. Without
     * the explicit-first check, `Step::prefix('calc')->create()`
     * would still write to the ambient prefix's table because
     * `newInstance()` propagates the bound table only if `getTable`
     * actually reads it.
     */
    public function getTable(): string
    {
        return $this->table ?? self::tableName();
    }

    public function stepTick()
    {
        return $this->belongsTo(StepsDispatcherTicks::class, 'tick_id');
    }

    public function scopeDispatchable(Builder $query)
    {
        return $query->where('state', Pending::class)
            ->where('type', 'default');
    }

    public function relatable()
    {
        return $this->morphTo();
    }

    public function scopePending(Builder $query)
    {
        // Qualify the column with the resolved table name so the
        // scope works in JOIN contexts under any active prefix.
        // Hardcoding `steps.state` would break for `trading_steps`.
        return $query->where($query->getModel()->getTable().'.state', Pending::class);
    }

    public function hasChildren(): bool
    {
        if (! $this->isParent()) {
            return false;
        }

        return self::where('block_uuid', $this->child_block_uuid)->exists();
    }

    public function parentStep()
    {
        return self::where('child_block_uuid', $this->block_uuid)->first();
    }

    public function isChild(): bool
    {
        return self::where('child_block_uuid', $this->block_uuid)->exists();
    }

    public function isParent(): bool
    {
        return ! empty($this->child_block_uuid);
    }

    /**
     * Whether any step above this one is Skipped. Nothing starts under a
     * Skipped ancestor, at any depth. The walk stops at a block it has already
     * seen, because nothing in the schema prevents a child_block_uuid loop.
     *
     * With $holdRows each ancestor stays share-locked until the transaction
     * ends. A worker's start claim uses this, so that a parent's skip and the
     * start of something inside it cannot both succeed: whichever takes the
     * parent's row second sees what the first one did.
     */
    public function hasSkippedAncestor(bool $holdRows = false): bool
    {
        $block = $this->block_uuid;
        $seen = [];

        while ($block !== null && ! isset($seen[$block])) {
            $seen[$block] = true;

            $parent = self::query()->where('child_block_uuid', $block)->first();

            // Locked by primary key. On MySQL a locking read through the
            // non-unique child_block_uuid index also locks the gaps around it,
            // and children of one parent starting at once deadlock on those.
            if ($parent !== null && $holdRows) {
                $parent = self::query()->whereKey($parent->getKey())->sharedLock()->first();
            }

            if ($parent === null) {
                return false;
            }

            if ($parent->state instanceof Skipped) {
                return true;
            }

            $block = $parent->block_uuid;
        }

        return false;
    }

    /**
     * Whether anything inside this parent, at any depth, has started. Skipped on
     * a parent means everything inside it was skipped, so a parent for which
     * this is true cannot become Skipped.
     *
     * A step has started once it reaches Dispatched or a later working state. A
     * retry or a reschedule keeps it counted after it goes back to Pending, and
     * after it later ends Cancelled or Skipped. started_at cannot answer this:
     * a retry clears it, and a step that skipped itself on its start check has
     * one without having done any work.
     *
     * These are plain reads. A worker's start locks its own row and then this
     * parent's, so locking a child here could deadlock with it; the parent's row
     * lock orders the two instead. That needs the read to see the latest data:
     * on MySQL, a job calling skipJob() inside its own transaction after an
     * earlier read gets that older snapshot and can miss a child started since.
     */
    public function hasChildThatStarted(): bool
    {
        if (! $this->isParent()) {
            return false;
        }

        $blocks = [$this->child_block_uuid];
        $seen = [];

        while ($blocks !== []) {
            $seen = array_merge($seen, $blocks);

            $inside = self::query()->whereIn('block_uuid', $blocks)->whereKeyNot($this->getKey());

            $started = (clone $inside)
                ->where(static function (Builder $query): void {
                    $query->whereIn('state', [
                        Dispatched::class,
                        Running::class,
                        Completed::class,
                        Failed::class,
                        Stopped::class,
                    ])
                        ->orWhere('retries', '>', 0)
                        ->orWhere('is_throttled', true)
                        ->orWhere('was_throttled', true);
                })
                ->exists();

            if ($started) {
                return true;
            }

            $blocks = array_values(array_diff(
                $inside->whereNotNull('child_block_uuid')->pluck('child_block_uuid')->unique()->all(),
                $seen,
            ));
        }

        return false;
    }

    /**
     * A resolve-exception step that is still NotRunnable (never activated).
     * These are inert on success paths and should not block parent completion.
     */
    public function isDormantResolveException(): bool
    {
        return $this->type === 'resolve-exception' && $this->state instanceof NotRunnable;
    }

    public function parentIsRunning(): bool
    {
        $parent = $this->parentStep();

        return $parent && $parent->state->equals(Running::class);
    }

    public function isOrphan(): bool
    {
        return is_null($this->child_block_uuid) && is_null($this->parentStep());
    }

    public function previousIndexIsConcluded()
    {
        if ($this->index === 1) {
            return true;
        }

        if ($this->index === null && $this->isChild() && $this->parentIsRunning()) {
            return true;
        }

        $hasPendingResolveException = self::where('block_uuid', $this->block_uuid)
            ->where('type', 'resolve-exception')
            ->where('state', Pending::class)
            ->exists();

        $query = self::where('block_uuid', $this->block_uuid)
            ->where('index', $this->index - 1);

        if ($hasPendingResolveException) {
            $query->where('type', 'resolve-exception');
        } else {
            $query->where('type', 'default');
        }

        $previousSteps = $query->get();

        if ($previousSteps->isEmpty()) {
            return false;
        }

        $previousSteps->each(static function ($step) {
            $step->refresh();
        });

        $result = $previousSteps->every(
            fn ($step) => in_array(get_class($step->state), $this->concludedStepStates(), strict: true)
        );

        return $result;
    }

    public function childSteps()
    {
        return $this->hasMany(self::class, 'block_uuid', 'child_block_uuid');
    }

    public function childStepsAreConcludedFromMap($childStepsByBlock, bool $forCompletion = false): bool
    {
        $visitedBlocks = [];

        return $this->childBlocksAreConcludedFromMap($childStepsByBlock, $forCompletion, $visitedBlocks);
    }

    /**
     * @param  array<string, true>  $visitedBlocks
     */
    private function childBlocksAreConcludedFromMap($childStepsByBlock, bool $forCompletion, array &$visitedBlocks): bool
    {
        // A block reached again adds no new steps. Stopping here also keeps
        // bad data with a cycle from exhausting memory and killing the tick.
        if (isset($visitedBlocks[$this->child_block_uuid])) {
            return true;
        }

        $visitedBlocks[$this->child_block_uuid] = true;

        $children = $childStepsByBlock[$this->child_block_uuid]
        ?? (method_exists($childStepsByBlock, 'get') ? $childStepsByBlock->get($this->child_block_uuid) : null);

        if (empty($children)) {
            return false;
        }

        if (! $children instanceof Collection) {
            $children = collect($children);
        }

        foreach ($children as $child) {
            $stateClass = get_class($child->state);

            if ($child->isDormantResolveException()) {
                continue;
            }

            // A child can fail after the failure sweep. Completion must
            // still wait for its Failed/Stopped outcome to propagate.
            if ($forCompletion && in_array($stateClass, [Failed::class, Stopped::class], true)) {
                return false;
            }

            if (! in_array($stateClass, self::terminalStepStates(), strict: true)) {
                return false;
            }

            if ($child->isParent()) {
                $recurse = $child->childBlocksAreConcludedFromMap($childStepsByBlock, $forCompletion, $visitedBlocks);
                if (! $recurse) {
                    return false;
                }
            }
        }

        return true;
    }

    public function childStepsAreConcluded(bool $forCompletion = false): bool
    {
        $visitedBlocks = [];

        return $this->childBlocksAreConcluded($forCompletion, $visitedBlocks);
    }

    /**
     * @param  array<string, true>  $visitedBlocks
     */
    private function childBlocksAreConcluded(bool $forCompletion, array &$visitedBlocks): bool
    {
        // See childBlocksAreConcludedFromMap(): each block is looked at once.
        if (isset($visitedBlocks[$this->child_block_uuid])) {
            return true;
        }

        $visitedBlocks[$this->child_block_uuid] = true;

        $children = $this->childSteps()->get();

        if ($children->isEmpty()) {
            return false;
        }

        foreach ($children as $child) {
            if ($child->isDormantResolveException()) {
                continue;
            }

            if ($forCompletion && in_array(get_class($child->state), [Failed::class, Stopped::class], true)) {
                return false;
            }

            if (! in_array(get_class($child->state), self::terminalStepStates(), strict: true)) {
                return false;
            }

            if ($child->isParent() && ! $child->childBlocksAreConcluded($forCompletion, $visitedBlocks)) {
                return false;
            }
        }

        return true;
    }

    public function getPrevious()
    {
        return self::where('block_uuid', $this->block_uuid)
            ->where('index', $this->index - 1)
            ->get();
    }

    protected static function newFactory()
    {
        return StepFactory::new();
    }
}
