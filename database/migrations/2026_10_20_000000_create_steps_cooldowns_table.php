<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per active cooldown. `scope` is `*` for the global cooldown
 * or the normalised table prefix of one scope (`''` for the default
 * table set, `trading_` for `trading_steps`, ...). The table is never
 * prefixed: a global cooldown must be visible from every scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('steps_cooldowns', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 100)->unique();
            $table->timestamp('cooled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('steps_cooldowns');
    }
};
