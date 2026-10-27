<?php

declare(strict_types=1);

namespace StepDispatcher\Concerns\Commands;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use StepDispatcher\Models\Step;
use StepDispatcher\States\NotRunnable;

/**
 * Shared tree operations for steps:archive and steps:purge.
 *
 * Both commands operate on whole step trees and share one safety
 * contract: a tree may only leave the live table when EVERY step in it
 * is settled (Step::settledStepStates()). Keeping the traversal and the
 * state list in one place prevents the two commands from drifting —
 * pre-extraction, archive accepted NotRunnable while purge did not, so
 * NotRunnable trees could be archived but never purged.
 */
trait InteractsWithStepTrees
{
    /**
     * Root block candidates: no parent step points at the block via
     * child_block_uuid, every step in the block is settled, and the
     * block's newest activity is older than the cutoff. A NotRunnable
     * resolver with a same-lane failed sibling is still owed promotion and
     * therefore is not a settled-tree candidate.
     *
     * @return Collection<int, string>
     */
    private function findSettledRoots(Carbon $cutoff): Collection
    {
        $settledStates = Step::settledStepStates();
        $placeholders = implode(',', array_fill(0, count($settledStates), '?'));
        $stepsTable = Step::tableName();

        return DB::table($stepsTable.' as s')
            ->select('s.block_uuid')
            ->whereNotExists(function ($q) use ($stepsTable) {
                $q->select(DB::raw(1))
                    ->from($stepsTable.' as parent')
                    ->whereColumn('parent.child_block_uuid', 's.block_uuid');
            })
            ->whereNotExists(function ($q) use ($stepsTable): void {
                $q->selectRaw('1')
                    ->from($stepsTable.' as dormant')
                    ->whereColumn('dormant.block_uuid', 's.block_uuid');

                $this->wherePromotableDormantResolver($q, $stepsTable, 'dormant');
            })
            ->groupBy('s.block_uuid')
            ->havingRaw("SUM(CASE WHEN s.state NOT IN ({$placeholders}) THEN 1 ELSE 0 END) = 0", $settledStates)
            ->havingRaw('MAX(COALESCE(s.completed_at, s.updated_at)) < ?', [$cutoff])
            ->pluck('s.block_uuid');
    }

    /**
     * Walk child_block_uuid recursively to collect every block_uuid in
     * the tree rooted at $rootUuid. Tracks visited blocks — nothing in
     * the schema prevents a child_block_uuid cycle, and an unguarded
     * walk would loop forever.
     *
     * @return list<string>
     */
    private function collectTree(string $rootUuid): array
    {
        $visited = [$rootUuid => true];
        $queue = [$rootUuid];

        while (! empty($queue)) {
            $childUuids = DB::table(Step::tableName())
                ->whereIn('block_uuid', $queue)
                ->whereNotNull('child_block_uuid')
                ->pluck('child_block_uuid')
                ->unique()
                ->values()
                ->toArray();

            $queue = [];

            foreach ($childUuids as $childUuid) {
                if (! isset($visited[$childUuid])) {
                    $visited[$childUuid] = true;
                    $queue[] = $childUuid;
                }
            }
        }

        return array_keys($visited);
    }

    /**
     * Verify every step across every block_uuid in the tree is settled.
     *
     * @param  list<string>  $treeUuids
     */
    private function treeIsFullySettled(array $treeUuids): bool
    {
        $stepsTable = Step::tableName();

        return DB::table($stepsTable.' as current')
            ->whereIn('current.block_uuid', $treeUuids)
            ->where(function ($query) use ($stepsTable): void {
                $query
                    ->whereNotIn('current.state', Step::settledStepStates())
                    ->orWhere(function ($promotable) use ($stepsTable): void {
                        $this->wherePromotableDormantResolver($promotable, $stepsTable, 'current');
                    });
            })
            ->doesntExist();
    }

    /**
     * In-transaction revive guard: re-check (under row locks where the
     * driver supports them) that no step in the chunk left the settled
     * set between the outer tree verification and this write — e.g. a
     * recover-stale tick requeueing a step mid-archive. Returning true
     * means the chunk must NOT be moved or deleted.
     *
     * @param  list<string>  $uuidChunk
     */
    private function chunkWasRevived(array $uuidChunk): bool
    {
        $stepsTable = Step::tableName();

        return DB::table($stepsTable.' as current')
            ->whereIn('current.block_uuid', $uuidChunk)
            ->where(function ($query) use ($stepsTable): void {
                $query
                    ->whereNotIn('current.state', Step::settledStepStates())
                    ->orWhere(function ($promotable) use ($stepsTable): void {
                        $this->wherePromotableDormantResolver($promotable, $stepsTable, 'current');
                    });
            })
            ->lockForUpdate()
            ->exists();
    }

    /**
     * Add the predicate shared by archive/purge checks for a resolver that
     * can still be promoted by the dispatcher. A parked resolver with no
     * matching failure remains settled and can retire normally; a failure in
     * another group cannot promote this row and is deliberately ignored.
     */
    private function wherePromotableDormantResolver($query, string $stepsTable, string $resolverAlias): void
    {
        $query
            ->where($resolverAlias.'.type', 'resolve-exception')
            ->where($resolverAlias.'.state', NotRunnable::class)
            ->whereExists(function ($failed) use ($stepsTable, $resolverAlias): void {
                $failed
                    ->selectRaw('1')
                    ->from($stepsTable.' as failing')
                    ->whereColumn('failing.block_uuid', $resolverAlias.'.block_uuid')
                    ->where('failing.type', '<>', 'resolve-exception')
                    ->whereIn('failing.state', Step::failedStepStates())
                    ->where(function ($group) use ($resolverAlias): void {
                        $group
                            ->whereColumn('failing.group', $resolverAlias.'.group')
                            ->orWhere(function ($global) use ($resolverAlias): void {
                                $global
                                    ->whereNull('failing.group')
                                    ->whereNull($resolverAlias.'.group');
                            });
                    });
            });
    }
}
