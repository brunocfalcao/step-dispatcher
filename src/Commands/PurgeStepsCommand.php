<?php

declare(strict_types=1);

namespace StepDispatcher\Commands;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use StepDispatcher\Concerns\Commands\InteractsWithStepTrees;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsArchive;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\Models\StepsDispatcherTicks;
use StepDispatcher\Support\BaseCommand;

final class PurgeStepsCommand extends BaseCommand
{
    use InteractsWithStepTrees;

    protected $signature = 'steps:purge
        {--days=30 : Keep records from the last N days (default: 30)}
        {--ticks : Purge ticks using the recordTickWhen callable (ignores --days for ticks)}
        {--only-archive : Purge ONLY the steps_archive table (date-based delete). Leaves the live steps table and ticks untouched.}
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

        // Archive-only mode is the cooled-down companion to
        // ArchiveStepsCommand. Archive moves terminal trees from `steps`
        // to `steps_archive` daily; eventually the archive needs to be
        // trimmed too. Because steps_archive is populated only by the
        // archive command (which guarantees every row is part of a
        // fully-terminal tree), it has no tree-safety constraint —
        // a flat date-based delete is correct.
        if ($this->option('only-archive')) {
            $this->verboseInfo("Purging steps_archive rows older than {$days} days (before {$cutoff->toDateTimeString()})...");

            $archiveDeleted = $this->batchDeleteByDate(StepsArchive::tableName(), $cutoff, $batchSize);
            $this->verboseInfo("Total deleted: {$archiveDeleted} archive records.");
            $this->verboseInfo('Archive purge completed.');

            return self::SUCCESS;
        }

        $this->verboseInfo("Purging records older than {$days} days (before {$cutoff->toDateTimeString()})...");

        // Ticks have no tree structure — safe to purge by date alone.
        $ticksDeleted = $this->batchDeleteByDate(StepsDispatcherTicks::tableName(), $cutoff, $batchSize);
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
        $rootUuids = $this->findSettledRoots($cutoff);

        if ($rootUuids->isEmpty()) {
            return 0;
        }

        $totalDeleted = 0;
        $treesPurged = 0;

        foreach ($rootUuids as $rootUuid) {
            $treeUuids = $this->collectTree($rootUuid);

            if (! $this->treeIsFullySettled($treeUuids)) {
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
     * Delete every step row belonging to the given tree, chunked to keep
     * the IN clause size and row locks short. Each chunk re-checks the
     * settled invariant inside its transaction so a step revived between
     * the outer verification and the delete is never destroyed.
     *
     * @param  list<string>  $treeUuids
     */
    private function deleteTree(array $treeUuids): int
    {
        $chunks = array_chunk($treeUuids, 100);
        $total = 0;

        foreach ($chunks as $uuidChunk) {
            $aborted = false;

            DB::transaction(function () use ($uuidChunk, &$total, &$aborted): void {
                if ($this->chunkWasRevived($uuidChunk)) {
                    $aborted = true;

                    return;
                }

                $total += DB::table(Step::tableName())
                    ->whereIn('block_uuid', $uuidChunk)
                    ->delete();
            });

            if ($aborted) {
                $this->verboseInfo('Tree purge aborted mid-flight: a step was revived; remaining chunks left live.');

                break;
            }
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
