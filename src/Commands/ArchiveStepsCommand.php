<?php

declare(strict_types=1);

namespace StepDispatcher\Commands;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use StepDispatcher\States\Cancelled;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;
use StepDispatcher\States\NotRunnable;
use StepDispatcher\States\Skipped;
use StepDispatcher\States\Stopped;
use StepDispatcher\Support\BaseCommand;

final class ArchiveStepsCommand extends BaseCommand
{
    protected $signature = 'steps:archive
        {--duration=5 : Keep steps from the last N days, archive older completed trees (default: 5)}
        {--output : Display command output (silent by default)}';

    protected $description = 'Archive fully-resolved step trees to steps_archive table.';

    /** @var list<string> States considered terminal for archiving (includes NotRunnable) */
    private array $archivableStates;

    public function handle(): int
    {
        $days = (int) $this->option('duration');

        if ($days < 1) {
            $this->verboseError('Duration must be at least 1 day.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);

        $this->archivableStates = [
            Completed::class,
            Skipped::class,
            Cancelled::class,
            Failed::class,
            Stopped::class,
            NotRunnable::class,
        ];

        $this->verboseInfo("Archiving step trees older than {$days} days (before {$cutoff->toDateTimeString()})...");

        // Phase 1: Find all archivable root block_uuids in a single query.
        $this->verboseInfo('Finding archivable root blocks...');
        $rootUuids = $this->findAllArchivableRoots($cutoff);
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

            if (! $this->treeIsFullyTerminal($treeUuids)) {
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
     * Find ALL root block_uuids that are candidates for archiving in a single query.
     *
     * A root block is one where no other step has child_block_uuid = this block_uuid.
     * Candidate roots have all steps in archivable states and latest completed_at before cutoff.
     *
     * @return Collection<int, string>
     */
    private function findAllArchivableRoots(Carbon $cutoff): Collection
    {
        $placeholders = $this->placeholders($this->archivableStates);

        return DB::table('steps as s')
            ->select('s.block_uuid')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('steps as parent')
                    ->whereColumn('parent.child_block_uuid', 's.block_uuid');
            })
            ->groupBy('s.block_uuid')
            ->havingRaw("SUM(CASE WHEN s.state NOT IN ({$placeholders}) THEN 1 ELSE 0 END) = 0", $this->archivableStates)
            ->havingRaw('MAX(COALESCE(s.completed_at, s.updated_at)) < ?', [$cutoff])
            ->pluck('s.block_uuid');
    }

    /**
     * Collect all block_uuids in a tree starting from the root.
     *
     * Walks child_block_uuid relationships recursively.
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
     * Verify ALL steps across ALL block_uuids in the tree are in archivable states.
     *
     * @param  list<string>  $treeUuids
     */
    private function treeIsFullyTerminal(array $treeUuids): bool
    {
        return DB::table('steps')
            ->whereIn('block_uuid', $treeUuids)
            ->whereNotIn('state', $this->archivableStates)
            ->doesntExist();
    }

    /**
     * Archive all steps in a tree: copy to steps_archive, then delete from steps.
     *
     * @param  list<string>  $treeUuids
     */
    private function archiveTree(array $treeUuids): int
    {
        $columns = [
            'id', 'block_uuid', 'type', '`group`', 'state', 'class', 'label',
            '`index`', 'response', 'error_message', 'error_stack_trace', 'step_log',
            'relatable_type', 'relatable_id', 'child_block_uuid', 'execution_mode',
            'double_check', 'tick_id', 'workflow_id', 'canonical', '`queue`',
            'arguments', 'retries', 'was_throttled', 'is_throttled', 'priority',
            'dispatch_after', 'started_at', 'completed_at', 'duration', 'hostname',
            'was_notified', 'created_at', 'updated_at',
        ];

        $columnList = implode(', ', $columns);

        // Chunk by block_uuid to avoid huge IN clauses
        $chunks = array_chunk($treeUuids, 100);
        $totalMoved = 0;

        foreach ($chunks as $uuidChunk) {
            $placeholders = implode(',', array_fill(0, count($uuidChunk), '?'));

            DB::transaction(function () use ($columnList, $placeholders, $uuidChunk, &$totalMoved) {
                DB::statement(
                    "INSERT INTO steps_archive ({$columnList})
                     SELECT {$columnList} FROM steps
                     WHERE block_uuid IN ({$placeholders})",
                    $uuidChunk
                );

                $deleted = DB::table('steps')
                    ->whereIn('block_uuid', $uuidChunk)
                    ->delete();

                $totalMoved += $deleted;
            });
        }

        return $totalMoved;
    }

    /**
     * Generate SQL placeholders for a list of values.
     */
    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
