<?php

declare(strict_types=1);

namespace StepDispatcher\Commands;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use StepDispatcher\Support\BaseCommand;

final class PurgeStepsCommand extends BaseCommand
{
    protected $signature = 'steps:purge
        {--days=30 : Keep records from the last N days (default: 30)}
        {--output : Display command output (silent by default)}';

    protected $description = 'Purge old steps and ticks records, keeping only the last N days.';

    public function handle(): int
    {
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
