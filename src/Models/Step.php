<?php

declare(strict_types=1);

namespace StepDispatcher\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\ModelStates\HasStates;
use StepDispatcher\Abstracts\BaseModel;
use StepDispatcher\Abstracts\StepStatus;
use StepDispatcher\Concerns\Step\HasActions;
use StepDispatcher\Concerns\Step\HasStepLogging;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\States\Pending;
use StepDispatcher\States\Running;
use StepDispatcher\States\Skipped;
use StepDispatcher\States\Stopped;
use StepDispatcher\Support\RuntimeContext;

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
 * @property \Illuminate\Support\Carbon|null $dispatch_after
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property int $duration
 * @property string|null $hostname
 * @property bool $was_notified
 * @property string|null $workflow_id
 * @property string|null $canonical
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read string|null $group
 */
final class Step extends BaseModel
{
    use HasActions, HasFactory, HasStates, HasStepLogging;

    protected $guarded = [];

    protected $casts = [
        'arguments' => 'array',
        'response' => 'array',

        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'dispatch_after' => 'datetime',

        'was_throttled' => 'boolean',
        'is_throttled' => 'boolean',

        'state' => StepStatus::class,
    ];

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
        $instance->setTable(\StepDispatcher\Support\Steps::normalise($prefix).'steps');

        return $instance->newQuery();
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

    public function childStepsAreConcludedFromMap($childStepsByBlock): bool
    {
        $children = $childStepsByBlock[$this->child_block_uuid]
        ?? (method_exists($childStepsByBlock, 'get') ? $childStepsByBlock->get($this->child_block_uuid) : null);

        if (empty($children)) {
            return false;
        }

        if (! $children instanceof \Illuminate\Support\Collection) {
            $children = collect($children);
        }

        foreach ($children as $child) {
            $stateClass = get_class($child->state);

            if ($child->isDormantResolveException()) {
                continue;
            }

            // A parent is ready to leave Running once every child reaches a
            // terminal state — Completed, Skipped, Cancelled, Failed, or
            // Stopped. Failed/Stopped cascades are handled by their own
            // parent-resolution passes BEFORE this method is consulted, so
            // by the time we reach here in `transitionParentsToComplete`,
            // any Failed/Stopped outcome has already moved the parent to
            // its matching terminal state. The only remaining case this
            // method decides on is Cancelled-or-concluded — and for that,
            // "child is in a terminal state" is the right signal.
            if (! in_array($stateClass, self::terminalStepStates(), strict: true)) {
                return false;
            }

            if ($child->isParent()) {
                $recurse = $child->childStepsAreConcludedFromMap($childStepsByBlock);
                if (! $recurse) {
                    return false;
                }
            }
        }

        return true;
    }

    public function childStepsAreConcluded(): bool
    {
        $children = $this->childSteps()->get();

        if ($children->isEmpty()) {
            return false;
        }

        foreach ($children as $child) {
            if ($child->isDormantResolveException()) {
                continue;
            }

            // See `childStepsAreConcludedFromMap` for the rationale on
            // using terminalStepStates() here instead of concluded-only.
            if (! in_array(get_class($child->state), self::terminalStepStates(), strict: true)) {
                return false;
            }

            if ($child->isParent() && ! $child->childStepsAreConcluded()) {
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
        return \StepDispatcher\Database\Factories\StepFactory::new();
    }
}
