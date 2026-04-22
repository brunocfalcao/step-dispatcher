<?php

declare(strict_types=1);

namespace StepDispatcher\Commands;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\Models\StepsDispatcherTicks;
use StepDispatcher\Support\BaseCommand;

final class PurgeStepsCommand extends BaseCommand
{
    protected $signature = 'steps:purge
        {--days=30 : Keep records from the last N days (default: 30)}
        {--ticks : Purge ticks using the recordTickWhen callable (ignores --days for ticks)}
        {--output : Display command output (silent by default)}';

    protected $description = 'Purge old steps and ticks records, keeping only the last N days.';

    public function handle(): int
    {
        // Purge ticks using the recordTickWhen callable
        if ($this->option('ticks')) {
            return $this->purgeTicksByCallable();
        }

        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->verboseError('Days must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);
        $batchSize = 10000;

        $this->verboseInfo("Purging records older than {$days} days (before {$cutoff->toDateTimeString()})...");

        // Ticks have no tree structure — safe to purge by date alone.
        $ticksDeleted = $this->batchDeleteByDate('steps_dispatcher_ticks', $cutoff, $batchSize);
        $this->verboseInfo("Total deleted: {$ticksDeleted} tick records.");

        // Steps must be purged tree-aware. Deleting a root whose descendants
        // are still live (long-running workflow, stuck leaf, branch spawned
        // recently) orphans the subtree, breaks the workflow, and loses the
        // audit trail. Only delete roots whose entire descendant tree is in
        // a terminal state.
        $stepsDeleted = $this->purgeTerminalTrees($cutoff);
        $this->verboseInfo("Total deleted: {$stepsDeleted} step records.");

        $this->verboseInfo('Purge completed.');

        return self::SUCCESS;
    }

    /**
     * Delete step rows in each root tree that is (a) older than the cutoff
     * and (b) fully terminal across the whole tree.
     *
     * Mirrors ArchiveStepsCommand's safety invariant without copying rows
     * to an archive table — this is a purge.
     */
    private function purgeTerminalTrees(Carbon $cutoff): int
    {
        $rootUuids = $this->findPurgeableRoots($cutoff);

        if ($rootUuids->isEmpty()) {
            return 0;
        }

        $totalDeleted = 0;
        $treesPurged = 0;

        foreach ($rootUuids as $rootUuid) {
            $treeUuids = $this->collectTree($rootUuid);

            if (! $this->treeIsFullyTerminal($treeUuids)) {
                continue;
            }

            $deleted = $this->deleteTree($treeUuids);
            $totalDeleted += $deleted;
            $treesPurged++;

            if ($treesPurged % 100 === 0) {
                $this->verboseInfo("Processed {$treesPurged} trees, deleted {$totalDeleted} steps so far...");
            }
        }

        return $totalDeleted;
    }

    /**
     * Root block candidates for purge: no parent step points at this block
     * via child_block_uuid, every step in the block is terminal, and the
     * block's newest activity is older than the cutoff.
     *
     * @return Collection<int, string>
     */
    private function findPurgeableRoots(Carbon $cutoff): Collection
    {
        $terminalStates = Step::terminalStepStates();
        $placeholders = implode(',', array_fill(0, count($terminalStates), '?'));

        return DB::table('steps as s')
            ->select('s.block_uuid')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('steps as parent')
                    ->whereColumn('parent.child_block_uuid', 's.block_uuid');
            })
            ->groupBy('s.block_uuid')
            ->havingRaw("SUM(CASE WHEN s.state NOT IN ({$placeholders}) THEN 1 ELSE 0 END) = 0", $terminalStates)
            ->havingRaw('MAX(COALESCE(s.completed_at, s.updated_at)) < ?', [$cutoff])
            ->pluck('s.block_uuid');
    }

    /**
     * Walk child_block_uuid recursively to collect every block_uuid in the
     * tree rooted at $rootUuid.
     *
     * @return list<string>
     */
    private function collectTree(string $rootUuid): array
    {
        $allUuids = [$rootUuid];
        $queue = [$rootUuid];

        while (! empty($queue)) {
            $childUuids = DB::table('steps')
                ->whereIn('block_uuid', $queue)
                ->whereNotNull('child_block_uuid')
                ->pluck('child_block_uuid')
                ->unique()
                ->values()
                ->toArray();

            if (empty($childUuids)) {
                break;
            }

            $allUuids = array_merge($allUuids, $childUuids);
            $queue = $childUuids;
        }

        return $allUuids;
    }

    /**
     * Verify every step across every block_uuid in the tree is terminal.
     *
     * @param  list<string>  $treeUuids
     */
    private function treeIsFullyTerminal(array $treeUuids): bool
    {
        return DB::table('steps')
            ->whereIn('block_uuid', $treeUuids)
            ->whereNotIn('state', Step::terminalStepStates())
            ->doesntExist();
    }

    /**
     * Delete every step row belonging to the given tree, chunked to keep
     * the IN clause size and row locks short.
     *
     * @param  list<string>  $treeUuids
     */
    private function deleteTree(array $treeUuids): int
    {
        $chunks = array_chunk($treeUuids, 100);
        $total = 0;

        foreach ($chunks as $uuidChunk) {
            $total += DB::table('steps')
                ->whereIn('block_uuid', $uuidChunk)
                ->delete();
        }

        return $total;
    }

    /**
     * Purge ticks that don't pass the recordTickWhen callable.
     * Loads ticks in chunks and evaluates the callable on each.
     */
    private function purgeTicksByCallable(): int
    {
        $callable = StepsDispatcher::getRecordTickWhenCallable();

        if ($callable === null) {
            $this->verboseError('No recordTickWhen callable registered. Register one in your Service Provider.');

            return self::FAILURE;
        }

        $totalDeleted = 0;
        $chunkSize = 1000;

        $this->verboseInfo('Purging ticks using recordTickWhen callable...');

        StepsDispatcherTicks::query()
            ->orderBy('id')
            ->chunk($chunkSize, function ($ticks) use ($callable, &$totalDeleted) {
                $idsToDelete = [];

                foreach ($ticks as $tick) {
                    if (! $callable($tick)) {
                        $idsToDelete[] = $tick->id;
                    }
                }

                if (! empty($idsToDelete)) {
                    StepsDispatcherTicks::whereIn('id', $idsToDelete)->delete();
                    $totalDeleted += count($idsToDelete);

                    if ($totalDeleted % 10000 === 0) {
                        $this->verboseInfo("Deleted {$totalDeleted} tick records so far...");
                    }
                }
            });

        $this->verboseInfo("Total deleted: {$totalDeleted} tick records.");

        return self::SUCCESS;
    }

    private function batchDeleteByDate(string $table, Carbon $cutoff, int $batchSize): int
    {
        $totalDeleted = 0;

        // Find max ID to delete (faster than date-based delete)
        $maxId = DB::table($table)
            ->where('created_at', '<', $cutoff)
            ->max('id');

        if (! $maxId) {
            return 0;
        }

        $this->verboseInfo("Deleting from {$table} where id <= {$maxId}...");

        // Delete in ID-based chunks (much faster, shorter locks)
        $currentId = 0;
        do {
            $deleted = DB::table($table)
                ->where('id', '>', $currentId)
                ->where('id', '<=', $currentId + $batchSize)
                ->where('id', '<=', $maxId)
                ->delete();

            $totalDeleted += $deleted;
            $currentId += $batchSize;

            if ($deleted > 0 && $totalDeleted % 50000 === 0) {
                $this->verboseInfo("Deleted {$totalDeleted} {$table} records so far...");
            }
        } while ($currentId <= $maxId);

        return $totalDeleted;
    }
}
