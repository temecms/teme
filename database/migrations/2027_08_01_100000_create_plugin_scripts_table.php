<?php

/**
 * A plugin's inline scripts and what a person decided about each
 * (docs/plans/plugin-sockets-2.md, B4): kept on a page only by its exact
 * text's hash, once approved for that plugin.
 *
 * @package Teme
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_scripts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('plugin_code', 64);
            $table->string('name', 80);
            $table->string('hash', 64);
            $table->text('script');
            $table->string('state', 16);
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestampTz('requested_at');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();

            $table->unique(['plugin_code', 'hash']);
            $table->index(['state', 'plugin_code']);
        });

        DB::statement("ALTER TABLE plugin_scripts ADD CONSTRAINT plugin_scripts_state_check CHECK (state IN ('waiting', 'approved', 'refused'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_scripts');
    }
};
