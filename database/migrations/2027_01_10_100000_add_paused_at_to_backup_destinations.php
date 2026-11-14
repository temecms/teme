<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * When a restore brought a place back, until a person says whether this
 * server is now the site: a paused place is never backed up into, so two
 * installs never write into one place. A removed place's pause counts for
 * nothing. docs/architecture/backups.md, "Restoring".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_destinations', function (Blueprint $table): void {
            $table->timestampTz('paused_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backup_destinations', function (Blueprint $table): void {
            $table->dropColumn('paused_at');
        });
    }
};
