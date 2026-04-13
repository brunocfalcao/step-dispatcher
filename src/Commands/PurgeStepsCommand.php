<?php

declare(strict_types=1);

namespace StepDispatcher\Commands;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        // Purge steps_dispatcher_ticks in batches by ID
        $ticksDeleted = $this->batchDeleteByDate('steps_dispatcher_ticks', $cutoff, $batchSize);
        $this->verboseInfo("Total deleted: {$ticksDeleted} tick records.");

        // Purge steps in batches by ID
        $stepsDeleted = $this->batchDeleteByDate('steps', $cutoff, $batchSize);
        $this->verboseInfo("Total deleted: {$stepsDeleted} step records.");

        $this->verboseInfo('Purge completed.');

        return self::SUCCESS;
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
