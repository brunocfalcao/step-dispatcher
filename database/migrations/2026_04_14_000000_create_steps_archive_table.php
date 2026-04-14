<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('steps_archive', function (Blueprint $table) {
            $table->id();
            $table->char('block_uuid', 36)->index();
            $table->string('type', 50)->default('default');
            $table->string('group', 50)->nullable();
            $table->string('state');
            $table->string('class')->nullable();
            $table->string('label')->nullable();
            $table->integer('index')->nullable();
            $table->longText('response')->nullable();
            $table->text('error_message')->nullable();
            $table->longText('error_stack_trace')->nullable();
            $table->text('step_log')->nullable();
            $table->string('relatable_type')->nullable();
            $table->unsignedBigInteger('relatable_id')->nullable();
            $table->char('child_block_uuid', 36)->nullable();
            $table->string('execution_mode', 50)->nullable();
            $table->tinyInteger('double_check')->default(0);
            $table->unsignedBigInteger('tick_id')->nullable();
            $table->char('workflow_id', 36)->nullable();
            $table->string('canonical', 100)->nullable();
            $table->string('queue', 50)->default('default');
            $table->json('arguments')->nullable();
            $table->integer('retries')->default(0);
            $table->tinyInteger('was_throttled')->default(0);
            $table->tinyInteger('is_throttled')->default(0);
            $table->string('priority', 20)->nullable();
            $table->timestamp('dispatch_after')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->bigInteger('duration')->default(0);
            $table->string('hostname', 100)->nullable();
            $table->tinyInteger('was_notified')->default(0);
            $table->timestamps();

            // Minimal indexes for archive lookups
            $table->index(['block_uuid', 'state'], 'idx_archive_block_state');
            $table->index(['workflow_id'], 'idx_archive_workflow');
            $table->index(['class', 'state'], 'idx_archive_class_state');
            $table->index(['created_at'], 'idx_archive_created_at');
            $table->index(['relatable_type', 'relatable_id'], 'idx_archive_relatable');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('steps_archive');
    }
};
