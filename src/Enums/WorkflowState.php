<?php

declare(strict_types=1);

namespace StepDispatcher\Enums;

/**
 * The one-word global answer to "how is this workflow doing", computed by
 * StepDispatcher::workflowState(). Consumers must use that method instead
 * of aggregating step rows themselves — the semantics live in exactly one
 * place so they can evolve without touching every call site.
 */
enum WorkflowState: string
{
    /** No live steps carry this workflow_id. Archived is archived. */
    case Unknown = 'unknown';

    /** Every step is still Pending — nothing has started. */
    case Pending = 'pending';

    /**
     * Work is in flight: a step is Dispatched/Running, a started workflow
     * still has Pending steps (throttled included), or a promoted
     * resolve-exception step is recovering a failure.
     */
    case Running = 'running';

    /**
     * A step Failed or was Stopped (including the Cancelled fallout of its
     * cascade) and nothing is left running. A completed resolve-exception
     * recovery does NOT flip this back — recovery restores a stable state,
     * not a successful one.
     */
    case Failed = 'failed';

    /** Every necessary step concluded; dormant resolvers are ignored. */
    case Completed = 'completed';

    public function isSettled(): bool
    {
        return $this === self::Failed || $this === self::Completed;
    }
}
