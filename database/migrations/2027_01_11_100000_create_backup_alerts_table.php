<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Every alert email about the backups that Teme decided to send (I-2409):
 * when something failed, a reminder a day later while it lasts, and when it
 * was fixed, with the problems it told. One row per decision; only
 * RaiseBackupAlert writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_alerts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kind', 16);
            $table->jsonb('problems')->default('[]');
            $table->timestampTz('raised_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE backup_alerts
                ADD CONSTRAINT backup_alerts_kind_check CHECK (kind IN ('failing', 'reminder', 'fixed')),
                ADD CONSTRAINT backup_alerts_problems_check CHECK (jsonb_typeof(problems) = 'array'),
                ADD CONSTRAINT backup_alerts_fixed_check CHECK ((kind = 'fixed') = (problems = '[]'::jsonb))
            SQL);

        // The newest alert, which every decision reads first.
        DB::statement('CREATE INDEX backup_alerts_raised_index ON backup_alerts (raised_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_alerts');
    }
};
