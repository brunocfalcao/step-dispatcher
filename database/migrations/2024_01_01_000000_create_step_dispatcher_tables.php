<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Steps table
        Schema::create('steps', function (Blueprint $table) {
            $table->id();
            $table->char('block_uuid', 36)->index();
            $table->string('type', 50)->default('default')->index();
            $table->string('group', 50)->nullable();
            $table->string('state')->index();
            $table->string('class')->nullable();
            $table->integer('index')->nullable();
            $table->longText('response')->nullable();
            $table->text('error_message')->nullable();
            $table->longText('error_stack_trace')->nullable();
            $table->text('step_log')->nullable();
            $table->string('relatable_type')->nullable()->index();
            $table->unsignedBigInteger('relatable_id')->nullable()->index();
            $table->char('child_block_uuid', 36)->nullable()->index();
            $table->string('execution_mode', 50)->nullable();
            $table->tinyInteger('double_check')->default(0);
            $table->unsignedBigInteger('tick_id')->nullable();
            $table->char('workflow_id', 36)->nullable()->index();
            $table->string('canonical', 100)->nullable();
            $table->string('queue', 50)->default('default');
            $table->json('arguments')->nullable();
            $table->integer('retries')->default(0);
            $table->tinyInteger('was_throttled')->default(0)->index();
            $table->tinyInteger('is_throttled')->default(0)->index();
            $table->string('priority', 20)->nullable()->index();
            $table->timestamp('dispatch_after')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->bigInteger('duration')->default(0);
            $table->string('hostname', 100)->nullable();
            $table->tinyInteger('was_notified')->default(0);
            $table->timestamps();

            // Composite indexes for better query performance
            $table->index(['block_uuid', 'child_block_uuid'], 'idx_steps_block_child_uuids');
            $table->index(['block_uuid', 'index'], 'steps_block_uuid_index_index');
            $table->index(['block_uuid', 'state'], 'steps_block_uuid_state_index');
            $table->index(['block_uuid', 'type'], 'steps_block_uuid_type_index');
            $table->index(['block_uuid', 'state', 'type'], 'steps_block_state_type_idx');
            $table->index(['block_uuid', 'index', 'type', 'state'], 'idx_steps_block_index_type_state');
            $table->index(['child_block_uuid', 'state'], 'idx_steps_child_uuid_state');
            $table->index(['state', 'child_block_uuid'], 'idx_steps_state_child_block_uuid');
            $table->index(['state', 'group', 'dispatch_after', 'type'], 'idx_steps_state_group_dispatch_type');
            $table->index(['group', 'state', 'dispatch_after'], 'steps_group_state_dispatch_after_idx');
            $table->index(['type', 'state'], 'steps_type_state_index');
            $table->index(['state', 'priority'], 'steps_state_priority_index');
            $table->index(['state', 'created_at'], 'idx_p_steps_state_created');
            $table->index(['dispatch_after', 'state'], 'idx_p_steps_dispatch_state');
            $table->index(['created_at', 'id'], 'idx_p_steps_created_id');
            $table->index(['relatable_type', 'relatable_id', 'created_at'], 'steps_rel_idx');
            $table->index(['relatable_type', 'relatable_id', 'state', 'index'], 'idx_p_steps_rel_state_idx');
            $table->index(['workflow_id', 'canonical'], 'steps_workflow_canonical_index');
            $table->index(['tick_id'], 'idx_steps_tick_id');
            $table->index(['created_at'], 'idx_steps_created_at');
        });

        // Steps dispatcher table (lock management)
        Schema::create('steps_dispatcher', function (Blueprint $table) {
            $table->id();
            $table->string('group', 50)->nullable()->unique();
            $table->tinyInteger('can_dispatch')->default(1)->index();
            $table->unsignedBigInteger('current_tick_id')->nullable()->index();
            $table->timestamp('last_tick_completed')->nullable()->index();
            $table->timestamp('last_selected_at')->nullable()->index();
            $table->timestamps();
        });

        // Seed dispatch groups from config
        $groups = config('step-dispatcher.groups.available', ['default']);
        $now = now();
        foreach ($groups as $group) {
            DB::table('steps_dispatcher')->insert([
                'group' => $group,
                'can_dispatch' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Steps dispatcher ticks table (tick tracking)
        Schema::create('steps_dispatcher_ticks', function (Blueprint $table) {
            $table->id();
            $table->string('group', 50)->nullable();
            $table->integer('progress')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration')->nullable();
            $table->timestamps();

            $table->index(['created_at'], 'ticks_created_idx');
            $table->index(['created_at', 'id'], 'idx_p_sdt_created_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('steps_dispatcher_ticks');
        Schema::dropIfExists('steps_dispatcher');
        Schema::dropIfExists('steps');
    }
};
