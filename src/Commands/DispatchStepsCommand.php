<?php

declare(strict_types=1);

namespace StepDispatcher\Commands;

use Illuminate\Support\Facades\DB;
use StepDispatcher\Support\BaseCommand;
use StepDispatcher\Support\StepDispatcher;
use Throwable;

final class DispatchStepsCommand extends BaseCommand
{
    /**
     * Usage:
     *  php artisan steps:dispatch
     *      → Dispatches for ALL groups found in steps_dispatcher.group (including NULL/global if present).
     *
     *  php artisan steps:dispatch --group=alpha
     *      → Dispatches only for "alpha".
     *
     *  php artisan steps:dispatch --group=alpha,beta
     *  php artisan steps:dispatch --group=alpha:beta
     *  php artisan steps:dispatch --group="alpha beta|gamma"
     *      → Dispatches for each listed name (comma/colon/semicolon/pipe/whitespace separated).
     */
    protected $signature = 'steps:dispatch {--group= : Single group or a list (comma/colon/semicolon/pipe/space separated)} {--output : Display command output (silent by default)}';

    protected $description = 'Dispatch all possible step entries (optionally filtered by --group).';

    public function handle(): int
    {
        // Early return if dispatcher is idle (no active steps, no flag file)
        if (! StepDispatcher::isActive()) {
            return self::SUCCESS;
        }

        try {
            $opt = $this->option('group');

            if (is_string($opt) && mb_trim($opt) !== '') {
                // Support multiple separators: , : ; | and whitespace
                $groups = preg_split('/[,\s;|:]+/', $opt, -1, PREG_SPLIT_NO_EMPTY) ?: [];

                // Normalize "null"/"NULL" to actual null (to target the global group if desired)
                $groups = array_map(callback: static function ($g) {
                    $g = mb_trim($g);

                    return ($g === '' || strcasecmp($g, 'null') === 0) ? null : $g;
                }, array: $groups);

                $groups = array_values(array_unique($groups));

                foreach ($groups as $group) {
                    StepDispatcher::dispatch($group);
                    $this->verboseInfo('Dispatched steps for group: '.($group === null ? 'NULL' : $group));
                }

                return self::SUCCESS;
            }

            // No --group provided: dispatch for ALL groups present in steps_dispatcher
            // (including a NULL/global row if it exists).
            $groups = DB::table('steps_dispatcher')
                ->select('group')
                ->distinct()
                ->pluck('group')
                ->all();

            // Safety: if table is empty, still try the NULL/global group once.
            if (empty($groups)) {
                $groups = [null];
            }

            foreach ($groups as $group) {
                StepDispatcher::dispatch($group);
                $this->verboseInfo('Dispatched steps for group: '.($group === null ? 'NULL' : $group));
            }
        } catch (Throwable $e) {
            report($e);
            $this->verboseError($e->getMessage());

            return self::SUCCESS;
        }

        return self::SUCCESS;
    }
}
