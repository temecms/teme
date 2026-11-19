<?php

/**
 * Migration: creates the directory index table.
 *
 * @package Teme
 * @since   next
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The plugin and theme directory's last good index, kept whole after its
 * signature was checked, and the last ask's result, in one row written only
 * by RecordDirectoryIndex. Its sequence is what a later index may not go below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_index', function (Blueprint $table): void {
            $table->smallInteger('id')->primary();
            $table->bigInteger('sequence')->nullable();
            $table->timestampTz('generated_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->jsonb('index')->nullable();
            $table->timestampTz('checked_at');
            $table->timestampTz('succeeded_at')->nullable();
            $table->text('error')->nullable();
        });

        DB::statement('ALTER TABLE directory_index ADD CONSTRAINT directory_index_one_row CHECK (id = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_index');
    }
};
