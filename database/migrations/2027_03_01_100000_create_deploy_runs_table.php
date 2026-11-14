<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Each teme:deploy that ran (I-?? draft), written by RecordDeployRun once it
 * ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deploy_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->text('version');
            $table->text('revision')->nullable();
            $table->text('outcome');
            $table->smallInteger('exit_code');
            $table->text('sentence');
            $table->text('backup_run_id')->nullable();
            $table->jsonb('report');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at');

            $table->index('finished_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_runs');
    }
};
