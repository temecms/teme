<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The update check's release list and its last result (I-?? draft), written
 * only by RecordReleaseCheck.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teme_releases', function (Blueprint $table): void {
            $table->text('version')->primary();
            $table->boolean('prerelease');
            $table->boolean('security');
            $table->boolean('maintenance');
            $table->text('notes');
            $table->text('url')->nullable();
            $table->timestampTz('published_at');
            $table->timestampTz('seen_at');
        });

        Schema::create('update_checks', function (Blueprint $table): void {
            $table->smallInteger('id')->primary();
            $table->timestampTz('checked_at');
            $table->timestampTz('succeeded_at')->nullable();
            $table->text('error')->nullable();
            $table->text('etag')->nullable();
            $table->boolean('token_set');
        });

        DB::statement('ALTER TABLE update_checks ADD CONSTRAINT update_checks_one_row CHECK (id = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('update_checks');
        Schema::dropIfExists('teme_releases');
    }
};
