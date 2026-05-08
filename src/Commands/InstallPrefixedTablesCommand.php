<?php

declare(strict_types=1);

namespace StepDispatcher\Commands;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use StepDispatcher\Support\BaseCommand;
use StepDispatcher\Support\Steps;

/**
 * `steps:install --prefix=trading`
 *
 * Creates a prefixed table set programmatically: `{prefix}steps`,
 * `{prefix}steps_dispatcher`, `{prefix}steps_dispatcher_ticks`,
 * `{prefix}steps_archive`. Index names are prefix-interpolated so they
 * never collide with the default set or another prefixed install.
 *
 * Idempotent per-table: each target is checked individually with
 * `Schema::hasTable()`. Existing tables are skipped, missing tables
 * are created. Re-running on a complete install is a no-op; running
 * after a partial drop heals the gap. The dispatcher seed (10 group
 * rows) only fires on a fresh dispatcher table — never on a skip
 * path — so re-runs cannot duplicate the seeded rows.
 *
 * Default prefix `''` is rejected — that's what the original
 * migrations install. Run `php artisan migrate` for the default set.
 */
final class InstallPrefixedTablesCommand extends BaseCommand
{
    protected $signature = 'steps:install {--prefix= : Prefix to install, e.g. trading or trading_. Required, non-empty.} {--output : Display command output (silent by default)}';

    protected $description = 'Install a prefixed step-dispatcher table set programmatically. Idempotent: existing tables are skipped, missing ones created.';

    public function handle(): int
    {
        $rawPrefix = (string) $this->option('prefix');

        if ($rawPrefix === '') {
            $this->verboseError('--prefix is required and cannot be empty. The default prefix is installed via the package migrations (php artisan migrate).');

            return self::FAILURE;
        }

        $prefix = Steps::normalise($rawPrefix);

        $this->verboseInfo("Installing prefixed table set with prefix=`{$prefix}`...");

        $created = [];
        $skipped = [];

        // Each step table maps to its create method. The dispatcher
        // entry is special-cased afterwards: when we genuinely create
        // it we also seed the per-group rows. Skipping the dispatcher
        // also skips the seed — re-runs cannot duplicate seeded rows.
        $plan = [
            'steps' => fn () => $this->createStepsTable($prefix),
            'steps_dispatcher' => fn () => $this->createDispatcherTable($prefix),
            'steps_dispatcher_ticks' => fn () => $this->createTicksTable($prefix),
            'steps_archive' => fn () => $this->createArchiveTable($prefix),
        ];

        foreach ($plan as $suffix => $creator) {
            $table = $prefix.$suffix;

            if (Schema::hasTable($table)) {
                $skipped[] = $table;

                continue;
            }

            $creator();
            $created[] = $table;

            if ($suffix === 'steps_dispatcher') {
                $this->seedDispatcherGroups($prefix);
            }
        }

        if (! empty($created)) {
            $this->verboseInfo('Created: '.implode(', ', $created));
        }

        if (! empty($skipped)) {
            $this->verboseInfo('Skipped (already exist): '.implode(', ', $skipped));
        }

        if (empty($created)) {
            $this->verboseInfo("No-op. Prefix `{$prefix}` is already fully installed.");
        } else {
            $this->verboseInfo("Done. Prefix `{$prefix}` is ready to receive `steps:dispatch --prefix={$rawPrefix}`.");
        }

        return self::SUCCESS;
    }

    private function createStepsTable(string $prefix): void
    {
        $table = $prefix.'steps';
        Schema::create($table, function (Blueprint $blueprint) use ($prefix): void {
            $blueprint->id();
            $blueprint->char('block_uuid', 36)->index();
            $blueprint->string('type', 50)->default('default')->index();
            $blueprint->string('group', 50)->nullable();
            $blueprint->string('state')->index();
            $blueprint->string('class')->nullable();
            $blueprint->string('label')->nullable();
            $blueprint->integer('index')->nullable();
            $blueprint->longText('response')->nullable();
            $blueprint->text('error_message')->nullable();
            $blueprint->longText('error_stack_trace')->nullable();
            $blueprint->text('step_log')->nullable();
            $blueprint->string('relatable_type')->nullable()->index();
            $blueprint->unsignedBigInteger('relatable_id')->nullable()->index();
            $blueprint->char('child_block_uuid', 36)->nullable()->index();
            $blueprint->string('execution_mode', 50)->nullable();
            $blueprint->tinyInteger('double_check')->default(0);
            $blueprint->unsignedBigInteger('tick_id')->nullable();
            $blueprint->char('workflow_id', 36)->nullable()->index();
            $blueprint->string('canonical', 100)->nullable();
            $blueprint->string('queue', 50)->default('default');
            $blueprint->json('arguments')->nullable();
            $blueprint->integer('retries')->default(0);
            $blueprint->tinyInteger('was_throttled')->default(0)->index();
            $blueprint->tinyInteger('is_throttled')->default(0)->index();
            $blueprint->string('priority', 20)->nullable()->index();
            $blueprint->timestamp('dispatch_after')->nullable()->index();
            $blueprint->timestamp('started_at')->nullable();
            $blueprint->timestamp('completed_at')->nullable();
            $blueprint->bigInteger('duration')->default(0);
            $blueprint->string('hostname', 100)->nullable();
            $blueprint->tinyInteger('was_notified')->default(0);
            $blueprint->timestamps();

            $blueprint->index(['block_uuid', 'child_block_uuid'], "idx_{$prefix}steps_block_child");
            $blueprint->index(['block_uuid', 'state', 'type'], "idx_{$prefix}steps_block_state_type");
            $blueprint->index(['block_uuid', 'index', 'type', 'state'], "idx_{$prefix}steps_block_idx_type_state");
            $blueprint->index(['child_block_uuid', 'state'], "idx_{$prefix}steps_child_state");
            $blueprint->index(['state', 'group', 'dispatch_after', 'type'], "idx_{$prefix}steps_state_grp_dsp_typ");
            $blueprint->index(['state', 'priority'], "idx_{$prefix}steps_state_priority");
            $blueprint->index(['state', 'created_at'], "idx_{$prefix}steps_state_created");
            $blueprint->index(['created_at', 'id'], "idx_{$prefix}steps_created_id");
            $blueprint->index(['relatable_type', 'relatable_id', 'state', 'index'], "idx_{$prefix}steps_rel_state");
            $blueprint->index(['workflow_id', 'canonical'], "idx_{$prefix}steps_wf_canonical");
            $blueprint->index(['tick_id'], "idx_{$prefix}steps_tick");
            $blueprint->index(['class', 'state', 'is_throttled'], "idx_{$prefix}steps_class_state_thr");
            $blueprint->index(['state', 'updated_at'], "idx_{$prefix}steps_state_updated");
        });
    }

    private function createDispatcherTable(string $prefix): void
    {
        $table = $prefix.'steps_dispatcher';
        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('group', 50)->nullable()->unique();
            $blueprint->tinyInteger('can_dispatch')->default(1)->index();
            $blueprint->unsignedBigInteger('current_tick_id')->nullable()->index();
            $blueprint->timestamp('last_tick_completed')->nullable()->index();
            $blueprint->timestamp('last_selected_at', 6)->nullable()->index();
            $blueprint->timestamps();
        });
    }

    private function seedDispatcherGroups(string $prefix): void
    {
        $groups = config('step-dispatcher.groups.available', ['default']);
        $now = now();
        foreach ($groups as $group) {
            DB::table($prefix.'steps_dispatcher')->insert([
                'group' => $group,
                'can_dispatch' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function createTicksTable(string $prefix): void
    {
        $table = $prefix.'steps_dispatcher_ticks';
        Schema::create($table, function (Blueprint $blueprint) use ($prefix): void {
            $blueprint->id();
            $blueprint->string('group', 50)->nullable();
            $blueprint->integer('progress')->default(0);
            $blueprint->timestamp('started_at')->nullable();
            $blueprint->timestamp('completed_at')->nullable();
            $blueprint->integer('duration')->nullable();
            $blueprint->timestamps();

            $blueprint->index(['created_at'], "idx_{$prefix}sdt_created");
            $blueprint->index(['created_at', 'id'], "idx_{$prefix}sdt_created_id");
        });
    }

    private function createArchiveTable(string $prefix): void
    {
        $table = $prefix.'steps_archive';
        Schema::create($table, function (Blueprint $blueprint) use ($prefix): void {
            $blueprint->id();
            $blueprint->char('block_uuid', 36)->index();
            $blueprint->string('type', 50)->default('default');
            $blueprint->string('group', 50)->nullable();
            $blueprint->string('state');
            $blueprint->string('class')->nullable();
            $blueprint->string('label')->nullable();
            $blueprint->integer('index')->nullable();
            $blueprint->longText('response')->nullable();
            $blueprint->text('error_message')->nullable();
            $blueprint->longText('error_stack_trace')->nullable();
            $blueprint->text('step_log')->nullable();
            $blueprint->string('relatable_type')->nullable();
            $blueprint->unsignedBigInteger('relatable_id')->nullable();
            $blueprint->char('child_block_uuid', 36)->nullable();
            $blueprint->string('execution_mode', 50)->nullable();
            $blueprint->tinyInteger('double_check')->default(0);
            $blueprint->unsignedBigInteger('tick_id')->nullable();
            $blueprint->char('workflow_id', 36)->nullable();
            $blueprint->string('canonical', 100)->nullable();
            $blueprint->string('queue', 50)->default('default');
            $blueprint->json('arguments')->nullable();
            $blueprint->integer('retries')->default(0);
            $blueprint->tinyInteger('was_throttled')->default(0);
            $blueprint->tinyInteger('is_throttled')->default(0);
            $blueprint->string('priority', 20)->nullable();
            $blueprint->timestamp('dispatch_after')->nullable();
            $blueprint->timestamp('started_at')->nullable();
            $blueprint->timestamp('completed_at')->nullable();
            $blueprint->bigInteger('duration')->default(0);
            $blueprint->string('hostname', 100)->nullable();
            $blueprint->tinyInteger('was_notified')->default(0);
            $blueprint->timestamps();

            $blueprint->index(['block_uuid', 'state'], "idx_{$prefix}archive_block_state");
            $blueprint->index(['workflow_id'], "idx_{$prefix}archive_wf");
            $blueprint->index(['class', 'state'], "idx_{$prefix}archive_class_state");
            $blueprint->index(['created_at'], "idx_{$prefix}archive_created");
            $blueprint->index(['relatable_type', 'relatable_id'], "idx_{$prefix}archive_rel");
        });
    }
}
