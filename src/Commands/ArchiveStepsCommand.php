<?php

declare(strict_types=1);

namespace StepDispatcher\Commands;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use StepDispatcher\Concerns\Commands\InteractsWithStepTrees;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsArchive;
use StepDispatcher\Support\BaseCommand;

final class ArchiveStepsCommand extends BaseCommand
{
    use InteractsWithStepTrees;

    protected $signature = 'steps:archive
        {--duration=5 : Keep steps from the last N days, archive older completed trees (default: 5)}
        {--output : Display command output (silent by default)}';

    protected $description = 'Archive fully-resolved step trees to steps_archive table.';

    public function handle(): int
    {
        $days = (int) $this->option('duration');

        if ($days < 1) {
            $this->verboseError('Duration must be at least 1 day.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);

        $this->verboseInfo("Archiving step trees older than {$days} days (before {$cutoff->toDateTimeString()})...");

        // Phase 1: Find all archivable root block_uuids in a single query.
        $this->verboseInfo('Finding archivable root blocks...');
        $rootUuids = $this->findSettledRoots($cutoff);
        $this->verboseInfo("Found {$rootUuids->count()} candidate root blocks.");

        if ($rootUuids->isEmpty()) {
            $this->verboseInfo('Nothing to archive.');

            return self::SUCCESS;
        }

        // Phase 2: Process each root — collect tree, verify, archive.
        $totalArchived = 0;
        $totalTrees = 0;

        foreach ($rootUuids as $rootUuid) {
            $treeUuids = $this->collectTree($rootUuid);

            if (! $this->treeIsFullySettled($treeUuids)) {
                continue;
            }

            $archived = $this->archiveTree($treeUuids);
            $totalArchived += $archived;
            $totalTrees++;

            if ($totalTrees % 100 === 0) {
                $this->verboseInfo("Processed {$totalTrees} trees, archived {$totalArchived} steps so far...");
            }
        }

        $this->verboseInfo("Archive completed. Trees: {$totalTrees}, steps: {$totalArchived}.");

        return self::SUCCESS;
    }

    /**
     * Archive all steps in a tree: copy to steps_archive, then delete from steps.
     *
     * @param  list<string>  $treeUuids
     */
    private function archiveTree(array $treeUuids): int
    {
        $columns = [
            'id', 'block_uuid', 'type', 'group', 'state', 'class', 'label',
            'index', 'response', 'error_message', 'error_stack_trace', 'step_log',
            'relatable_type', 'relatable_id', 'child_block_uuid', 'execution_mode',
            'double_check', 'tick_id', 'workflow_id', 'canonical', 'queue',
            'arguments', 'retries', 'was_throttled', 'is_throttled', 'priority',
            'dispatch_after', 'started_at', 'completed_at', 'duration', 'hostname',
            'was_notified', 'created_at', 'updated_at',
        ];

        // Quote every column through the connection's grammar so reserved words
        // (group, index, queue) are valid in the raw INSERT…SELECT on any
        // driver — backticks on MySQL, double-quotes on PostgreSQL.
        $columnList = implode(', ', array_map(
            static fn (string $column): string => Step::wrapColumn($column),
            $columns,
        ));

        // Chunk by block_uuid to avoid huge IN clauses
        $chunks = array_chunk($treeUuids, 100);
        $totalMoved = 0;

        // Resolve both source and destination through the model
        // helpers so INSERT-from-SELECT honours the active runtime
        // prefix end-to-end. Hardcoding either name would silently
        // copy the wrong table set under a prefixed dispatcher.
        $stepsTable = Step::tableName();
        $archiveTable = StepsArchive::tableName();

        foreach ($chunks as $uuidChunk) {
            $placeholders = implode(',', array_fill(0, count($uuidChunk), '?'));

            $aborted = false;

            DB::transaction(function () use ($columnList, $placeholders, $uuidChunk, $stepsTable, $archiveTable, &$totalMoved, &$aborted) {
                // The outer tree verification ran before this transaction.
                // A step revived in between (recover-stale requeue, retry)
                // must not be copied to the archive and deleted while live.
                if ($this->chunkWasRevived($uuidChunk)) {
                    $aborted = true;

                    return;
                }

                DB::statement(
                    "INSERT INTO {$archiveTable} ({$columnList})
                     SELECT {$columnList} FROM {$stepsTable}
                     WHERE block_uuid IN ({$placeholders})",
                    $uuidChunk
                );

                $deleted = DB::table($stepsTable)
                    ->whereIn('block_uuid', $uuidChunk)
                    ->delete();

                $totalMoved += $deleted;
            });

            if ($aborted) {
                $this->verboseInfo('Tree archive aborted mid-flight: a step was revived; remaining chunks left live.');

                break;
            }
        }

        return $totalMoved;
    }
}
